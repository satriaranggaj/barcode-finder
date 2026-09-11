"""Local, labelled tuning diagnostic. Does not write runtime config or database.

Usage: python -B benchmark_object_first.py --dataset benchmark-output/labelled-dumbbell-20260912
       --model benchmark-output/object-first/models/u2netp.onnx --output benchmark-output/object-first/run1
"""
import argparse
import hashlib
import json
import os
from pathlib import Path
import statistics
from time import perf_counter

os.environ['HF_HUB_OFFLINE'] = '1'
os.environ['CUDA_VISIBLE_DEVICES'] = ''
os.environ['AI_CPU_THREADS'] = '2'
import numpy as np
import psutil
from PIL import Image, ImageDraw
from app.preprocessing import decode, cv2
from app.conditional_preprocessing import prepare as v2
from app.model import create_image_embedding
from object_first_experiment import SaliencyModel, prepare, features, fuse, VERSION, MODEL_SHA256
from relevance_benchmark import evaluate, fingerprint


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--dataset', type=Path, required=True)
    parser.add_argument('--model', type=Path, required=True)
    parser.add_argument('--output', type=Path, required=True)
    parser.add_argument('--repeats', type=int, default=3)
    args = parser.parse_args()
    if args.repeats < 1:
        parser.error('repeats must be positive')
    args.output.mkdir(parents=True, exist_ok=False)
    cv2.setNumThreads(1)
    segmenter = SaliencyModel(args.model)
    refs = [json.loads(line) for line in (args.dataset/'catalog.jsonl').read_text().splitlines()]
    queries = json.loads((args.dataset/'manifest.json').read_text())['queries'] + json.loads((args.dataset/'positive-queries.json').read_text())
    ref_hashes = {r['sha256'] for r in refs}
    for q in queries:
        if q['query_sha256'] in ref_hashes:
            raise ValueError('query_reference_leakage')
    base = json.loads((args.dataset/'legacy/pipeline.json').read_text())
    results, audit = [], []
    for mode in ('object-v2', VERSION):
        prepare_fn = v2 if mode == 'object-v2' else lambda image: prepare(image, segmenter)
        reference_features = []
        for row in refs:
            data = Path(row['path']).read_bytes()
            if hashlib.sha256(data).hexdigest() != row['sha256']:
                raise ValueError('reference_changed')
            image, _ = decode(data)
            prepared = prepare_fn(image)
            reference_features.append(dict(sku=row['sku'], embedding=create_image_embedding(prepared.image), descriptors=features(prepared)))
            if mode == VERSION:
                audit.append((str(row['sku']), image.copy(), prepared.image.copy(), prepared.metadata))
        query_features = []
        peak = psutil.Process().memory_info().rss
        for row in queries:
            data = Path(row['path']).read_bytes()
            if hashlib.sha256(data).hexdigest() != row['query_sha256']:
                raise ValueError('query_changed')
            samples = []
            for _ in range(args.repeats):
                start = perf_counter()
                image, _ = decode(data)
                prepared = prepare_fn(image)
                embedding = create_image_embedding(prepared.image)
                # Offline upper-cost ablation: all query features, NOT production lazy latency.
                descriptors = features(prepared)
                samples.append((perf_counter()-start)*1000)
                peak = max(peak, psutil.Process().memory_info().rss)
            query_features.append(dict(row, embedding=embedding, descriptors=descriptors, samples=samples))
            if mode == VERSION:
                audit.append((row['query_id'], image.copy(), prepared.image.copy(), prepared.metadata))
            print(mode, row['query_id'], round(statistics.median(samples), 1), flush=True)
        presets = {
            'global': ({'global': 1}, {}),
            'detail-ablation': ({'global': 1, 'shape': .1, 'proportion': .1, 'texture': .1, 'pattern': .1, 'color': .05}, {}),
            'mismatch-ablation': ({'global': 1}, {'shape': .1, 'proportion': .1, 'texture': .1, 'pattern': .1}),
        }
        for name, (weights, penalties) in presets.items():
            for k in (20, 30, 50):
                folder = args.output/f'{mode}-{name}-k{k}'
                folder.mkdir()
                pipeline = dict(base, preprocessing_version=mode, k=k,
                    descriptor_weights={key: val for key, val in weights.items() if key != 'pattern'},
                    experimental_pattern_weight=weights.get('pattern', 0), mismatch_penalties=penalties,
                    segmentation_sha256=MODEL_SHA256 if mode == VERSION else None,
                    latency_scope='warm CPU decode+isolation+CLIP+all query descriptors+offline Top-K fusion; excludes HTTP/queue/ANN; median of repeated query samples',
                    reference_source='fresh same-pipeline features', diagnostic_only=True)
                rows, values = [], set()
                for q in query_features:
                    start = perf_counter()
                    candidates = sorted([(float(np.dot(q['embedding'], r['embedding'])), r) for r in reference_features], key=lambda pair: pair[0], reverse=True)[:k]
                    scored = [dict(sku=r['sku'], raw_cosine=score, final_score=fuse(score, q['descriptors'], r['descriptors'], weights, penalties)) for score, r in candidates]
                    elapsed = (perf_counter()-start)*1000
                    values.update(c['final_score'] for c in scored)
                    rows.append(dict(split='tuning', query_id=q['query_id'], capture_group=q['capture_group'],
                        query_sha256=q['query_sha256'], pipeline_sha256=fingerprint(pipeline), labels_complete=True,
                        relevant_skus=q['relevant_skus'], candidates=scored, latency_ms=statistics.median(q['samples'])+elapsed))
                dataset = folder/'tuning.jsonl'
                dataset.write_text(''.join(json.dumps(r)+'\n' for r in rows), encoding='utf-8')
                ordered = sorted(values)
                thresholds = sorted(set([-1, 1, .72] + [(a+b)/2 for a,b in zip(ordered, ordered[1:])]))
                report = evaluate(dataset, pipeline, thresholds, 'tuning')
                report['sampled_process_rss_mib'] = peak/1024**2
                report['repeats_per_query'] = args.repeats
                report['full_negative_rejection_best_positive_recall'] = max((t['positive_recall'] for t in report['thresholds'] if t['negative_rejection'] == 1), default=None)
                (folder/'report.json').write_text(json.dumps(report, indent=2), encoding='utf-8')
                (folder/'pipeline.json').write_text(json.dumps(pipeline, indent=2), encoding='utf-8')
                results.append(dict(pipeline=folder.name, **{key: report[key] for key in ('top1','top5','mrr','recall_at_k','median_ms','p95_ms','sampled_process_rss_mib','full_negative_rejection_best_positive_recall')},
                                    diagnostic_at_existing_072=next(t for t in report['thresholds'] if t['final_min_score'] == .72)))
    canvas = Image.new('RGB', (600, len(audit)*180), 'white')
    draw = ImageDraw.Draw(canvas)
    metadata = []
    for i, (label, original, prepared, meta) in enumerate(audit):
        for x, img in ((0,original), (210,prepared)):
            img.thumbnail((200,160))
            canvas.paste(img, (x,i*180+20))
        draw.text((0,i*180), label, fill='black')
        draw.text((420,i*180+20), meta.get('path',''), fill='black')
        metadata.append(dict(label=label, **meta))
    canvas.save(args.output/'mask-audit.jpg')
    (args.output/'mask-audit.json').write_text(json.dumps(metadata, indent=2))
    (args.output/'summary.json').write_text(json.dumps(dict(acceptance_ready=False, held_out=False, results=results), indent=2))


if __name__ == '__main__':
    main()
