"""Held-out evaluation; no parameter/threshold tuning occurs in this command."""
import argparse
import hashlib
import json
import logging
from pathlib import Path
import numpy as np
from ..config import Settings
from ..preprocessing.pipeline import photo_hash
from ..search.service import RetrievalService, load_encoders
from ..search.faiss_index import FaissIndexManager, current_generation
from .dataset import iter_images, read_photo
from ..preprocessing.selection import BoundingBox
from .calibrate import metrics, ranking_metrics


def evaluate(dataset: Path, service: RetrievalService, use_selection: bool = True) -> dict:
    if service.index is None or not service.index.count:
        raise ValueError('Evaluation requires a nonempty reference index')
    identities = set()
    hashes_by_path = {}
    metadata_by_path = {}
    groups = set()
    columns = {row[1] for row in service.index.db.execute('PRAGMA table_info(refs)')}
    reference_groups = set()
    reference_source_hashes = set()
    if 'extra' in columns:
        for row in service.index.db.execute('SELECT extra FROM refs'):
            extra = json.loads(row[0])
            if extra.get('capture_group'):
                reference_groups.add(extra['capture_group'])
            if extra.get('source_photo_hash'):
                reference_source_hashes.add(extra['source_photo_hash'])
    for _, path in iter_images(dataset):
        identity = photo_hash(read_photo(path, service.settings))
        if identity in identities or identity in reference_source_hashes or service.index.has_photo_hash(identity):
            raise ValueError(f'Duplicate/reference leakage: {path.name}')
        identities.add(identity)
        hashes_by_path[path] = identity
        sidecar = path.with_suffix('.json')
        metadata = json.loads(sidecar.read_text(encoding='utf-8')) if sidecar.exists() else {}
        if not isinstance(metadata, dict):
            raise ValueError('Query metadata must be an object')
        tags = metadata.get('tags', [])
        if not isinstance(tags, list) or not all(isinstance(tag, str) and tag for tag in tags):
            raise ValueError('Query tags must be a list of nonempty strings')
        metadata_by_path[path] = metadata
        group = metadata.get('capture_group')
        if group and group in reference_groups:
            raise ValueError(f'Capture-group/reference leakage: {path.name}')
        if group:
            groups.add(group)
    if not identities:
        raise ValueError('Evaluation contains no supported images')
    # Expected-SKU family comes from the reference index itself; missing
    # metadata stays an explicit 'unknown' bucket, never an invented label.
    families: dict[str, str | None] = {}
    ref_columns = {row[1] for row in service.index.db.execute('PRAGMA table_info(refs)')}
    if 'family' in ref_columns:
        for ref_sku, family in service.index.db.execute('SELECT DISTINCT sku, family FROM refs'):
            families.setdefault(ref_sku, family)
    hits = {1: 0, 3: 0, 5: 0}
    reciprocal, latency, rows = [], [], []
    counts = {'tp':0, 'fp':0, 'tn':0, 'fn':0, 'positive_queries':0, 'negative_queries':0, 'positive_false_rejections':0}
    for sku, path in iter_images(dataset):
        sidecar = path.with_suffix('.json')
        metadata = metadata_by_path[path]
        current_metadata = json.loads(sidecar.read_text(encoding='utf-8')) if sidecar.exists() else {}
        if current_metadata != metadata:
            raise ValueError('Query metadata changed during evaluation')
        relevant = metadata.get('relevant_skus', [] if sku == '__no_match__' else [sku])
        if not isinstance(relevant,list) or not all(isinstance(value,str) for value in relevant):
            raise ValueError('relevant_skus must be a list of SKU strings')
        box = BoundingBox(**metadata['crop']) if metadata.get('crop') else None
        if not use_selection: box = None
        image = read_photo(path, service.settings)
        identity = photo_hash(image)
        if identity != hashes_by_path[path]: raise ValueError('Query changed during evaluation')
        result = service.search(image, 50, mode=service.settings.preprocessing_mode, box=box)
        rank = next((i + 1 for i, row in enumerate(result['results']) if row['sku'] in relevant), None)
        returned = {row['sku'] for row in result['results'][:5]}
        expected = set(relevant)
        counts['tp'] += len(returned & expected)
        counts['fp'] += len(returned - expected)
        counts['fn'] += len(expected - returned)
        counts['tn'] += int(not expected and not returned)
        counts['positive_queries' if expected else 'negative_queries'] += 1
        counts['positive_false_rejections'] += int(bool(expected) and not returned)
        for k in hits:
            hits[k] += int(rank is not None and rank <= k)
        if expected:
            reciprocal.append(1/rank if rank else 0)
        latency.append(result['latency_ms'])
        top = result['results'][0] if result['results'] else None
        rows.append({'query': path.relative_to(dataset).as_posix(), 'expected_sku': sku,
                     'relevant_skus': relevant, 'photo_hash': identity,
                     'capture_group': metadata.get('capture_group'), 'tags': metadata.get('tags', []),
                     'expected_families': sorted({families.get(sku) or 'unknown' for sku in relevant}),
                     'predicted_sku': top['sku'] if top else None,
                     'ambiguous': result.get('ambiguous', False),
                     'ambiguity_reason': result.get('ambiguity_reason'),
                     'rank': rank, 'results': result['results'], 'latency_ms': result['latency_ms']})
        if len(rows) % 25 == 0:
            print(f'Evaluated {len(rows)}', flush=True)
    positive = counts['positive_queries']
    digest = hashlib.sha256()
    for path in sorted(hashes_by_path, key=lambda p: p.relative_to(dataset).as_posix()):
        digest.update(path.relative_to(dataset).as_posix().encode())
        digest.update(hashes_by_path[path].encode())
        digest.update(json.dumps(metadata_by_path[path], sort_keys=True, separators=(',', ':')).encode())
    dataset_version = f'eval-{digest.hexdigest()[:16]}'
    report = {'images_tested': len(rows), 'dataset_version': dataset_version,
              **{f'top_{k}_accuracy': value/max(1,positive) for k, value in hits.items()},
            **counts, 'precision_at_5': counts['tp']/max(1,counts['tp']+counts['fp']),
            'recall_at_5': counts['tp']/max(1,counts['tp']+counts['fn']),
            'negative_rejection': counts['tn']/max(1,counts['negative_queries']),
            'positive_query_false_rejection': counts['positive_false_rejections']/max(1,positive),
            'evaluation_signature': service.evaluation_signature, 'gate_applied': service.policy is not None,
            'mrr': float(np.mean(reciprocal)) if reciprocal else 0, 'mrr_cutoff': 50, 'median_latency_ms': float(np.median(latency)),
            'p95_latency_ms': float(np.percentile(latency, 95)), 'pipeline': service.index.signature,
            'weights': service.settings.weights, 'local_weight': service.settings.local_weight,
            'queries': rows}
    report.update(metrics(rows, -float('inf')))
    report.update(ranking_metrics(rows, mrr_cutoff=50))
    report['by_tag'] = {tag: {**metrics([r for r in rows if tag in r['tags']],-float('inf')),
                            **ranking_metrics([r for r in rows if tag in r['tags']], mrr_cutoff=50)}
                        for tag in sorted({tag for r in rows for tag in r['tags']})}
    report['by_family'] = {family: {**metrics([r for r in rows if family in r['expected_families']],-float('inf')),
                            **ranking_metrics([r for r in rows if family in r['expected_families']], mrr_cutoff=50)}
                        for family in sorted({family for r in rows for family in r['expected_families']})}
    # Failed Top-1 error analysis: expected vs predicted, rank, component
    # scores of the wrong winner, its matched reference, and ambiguity state.
    # Paths and hashes only; image bytes never enter reports.
    report['errors'] = [{'query': row['query'], 'expected_sku': row['expected_sku'],
                         'predicted_sku': row['predicted_sku'], 'rank': row['rank'],
                         'predicted': {key: (row['results'][0] if row['results'] else {}).get(key) for key in
                                       ('sku', 'score', 'visual_score', 'object_score', 'global_score',
                                        'siglip_score', 'dino_score', 'local_score', 'text_score',
                                        'ocr_score', 'image_id', 'matched_images')},
                         'ambiguous': row['ambiguous'], 'ambiguity_reason': row['ambiguity_reason'],
                         'capture_group': row['capture_group'], 'tags': row['tags']}
                        for row in rows if row['relevant_skus'] and row['rank'] != 1]
    return report


def format_summary(report: dict) -> str:
    """One-screen human summary; the JSON report stays machine-readable."""
    lines = [
        f"images={report['images_tested']} dataset={report['dataset_version']}",
        f"top-1={report['top_1_accuracy']:.3f} top-3={report['top_3_accuracy']:.3f} "
        f"top-5={report['top_5_accuracy']:.3f} mrr={report['mrr']:.3f}",
        f"latency median={report['median_latency_ms']:.1f}ms p95={report['p95_latency_ms']:.1f}ms",
        f"negatives rejected={report['negative_rejection']:.3f} "
        f"positives missed={report['positive_query_false_rejection']:.3f}",
    ]
    return '\n'.join(lines)


def main() -> None:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--dataset', type=Path, default=Path('evaluation'))
    parser.add_argument('--output', type=Path, default=Path('evaluation-report.json'))
    args = parser.parse_args()
    logging.basicConfig(level=logging.INFO)
    settings = Settings.from_env()
    index = FaissIndexManager.load_index(current_generation(settings.index_path))
    try:
        report = evaluate(args.dataset, RetrievalService(settings, load_encoders(settings), index))
        args.output.write_text(json.dumps(report, indent=2), encoding='utf-8')
        print(format_summary(report))
        print(json.dumps({key: value for key, value in report.items() if key != 'queries'}, indent=2))
    finally:
        index.close()


if __name__ == '__main__':
    main()
