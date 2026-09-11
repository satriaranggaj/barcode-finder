"""Offline relevance evaluation of scored Top-K exports. Never modifies runtime config.

JSONL keeps candidate memory bounded independently of reference catalog size.
Scores must come from the existing retrieval/reranking pipeline, before filtering.
"""
import argparse
import hashlib
import json
import math
from pathlib import Path
import statistics

POLICY_FIELDS = ('model', 'model_revision', 'preprocessing_version', 'k', 'ef_search',
                 'confidence_similarity', 'confidence_margin', 'descriptor_weights',
                 'catalog_sha256', 'latency_scope', 'result_limit')


def read_json(path):
    return json.loads(Path(path).read_text(encoding='utf-8-sig'))


def fingerprint(value):
    return hashlib.sha256(json.dumps(value, sort_keys=True, allow_nan=False).encode()).hexdigest()


def score(value):
    if isinstance(value, bool) or not isinstance(value, (int, float)) or not math.isfinite(value) or not -1 <= value <= 1:
        raise ValueError('Scores/thresholds must be finite numbers in [-1, 1]')
    return float(value)


def configuration(value):
    if not isinstance(value, dict) or any(key not in value for key in POLICY_FIELDS):
        raise ValueError('Pipeline configuration is incomplete')
    for key in ('k', 'ef_search', 'result_limit'):
        if type(value[key]) is not int or value[key] < 1:
            raise ValueError('Invalid pipeline limits')
    if value['k'] > 100 or value['result_limit'] > 100:
        raise ValueError('Export bounded Top-K (maximum 100), not the whole catalog')
    for key in ('model', 'model_revision', 'preprocessing_version', 'latency_scope'):
        if not isinstance(value[key], str) or not value[key].strip():
            raise ValueError('Invalid pipeline identity: ' + key)
    if not valid_hash(value['catalog_sha256']):
        raise ValueError('Invalid catalog SHA256')
    score(value['confidence_similarity'])
    score(value['confidence_margin'])
    if value['confidence_margin'] < 0 or not value['k'] <= value['ef_search'] <= 1000:
        raise ValueError('Invalid effective adaptive/HNSW configuration')
    weights = value['descriptor_weights']
    if not isinstance(weights, dict) or not weights or set(weights) - {'global', 'shape', 'color', 'texture', 'local', 'proportion'}:
        raise ValueError('Invalid descriptor weights')
    for weight in weights.values():
        if isinstance(weight, bool) or not isinstance(weight, (int, float)) or not math.isfinite(weight) or weight < 0:
            raise ValueError('Weights must be finite and nonnegative')
    return value


def valid_hash(value):
    return isinstance(value, str) and len(value) == 64 and all(c in '0123456789abcdef' for c in value)


def rows(path, split, pipeline_hash):
    with Path(path).open(encoding='utf-8-sig') as stream:
        while True:
            line = stream.readline(2 * 1024 * 1024 + 1)
            if not line:
                break
            if len(line) > 2 * 1024 * 1024:
                raise ValueError('Oversized JSONL row')
            if not line.strip():
                continue
            row = json.loads(line)
            if not isinstance(row, dict):
                raise ValueError('JSONL row must be an object')
            if row.get('split') != split or row.get('pipeline_sha256') != pipeline_hash:
                raise ValueError('Split or pipeline fingerprint mismatch')
            if row.get('labels_complete') is not True:
                raise ValueError('Complete SKU relevance labels are required; unknown is not negative')
            for key in ('query_id', 'capture_group', 'query_sha256'):
                if not isinstance(row.get(key), str) or not row[key]:
                    raise ValueError('Query identity, capture group and source hash are required')
            digest = row['query_sha256']
            if not valid_hash(digest):
                raise ValueError('Invalid query SHA256')
            if not isinstance(row.get('relevant_skus'), list) or any(not isinstance(s, str) or not s for s in row['relevant_skus']):
                raise ValueError('relevant_skus must be a list of SKU strings; [] means out-of-catalog')
            if len(row['relevant_skus']) != len(set(row['relevant_skus'])):
                raise ValueError('Duplicate relevant SKU label')
            if not isinstance(row.get('candidates'), list) or len(row['candidates']) > 100:
                raise ValueError('Invalid candidate list')
            for candidate in row['candidates']:
                if not isinstance(candidate, dict) or not isinstance(candidate.get('sku'), str) or not candidate['sku']:
                    raise ValueError('Candidate SKU missing')
                score(candidate['raw_cosine'])
                score(candidate['final_score'])
            yield row


def divide(a, b):
    return a / b if b else None


def evaluate(path, pipeline, thresholds, split, forbidden=None):
    if split not in ('tuning', 'evaluation'):
        raise ValueError('Unknown split')
    source_hash = file_hash(path)
    pipeline = configuration(pipeline)
    pipeline_hash = fingerprint(pipeline)
    thresholds = sorted(set(score(t) for t in thresholds))
    if not 1 <= len(thresholds) <= 201:
        raise ValueError('Supply 1..201 explicit trial thresholds')
    forbidden = forbidden or {}
    forbidden = {key: set(forbidden.get(key, [])) for key in ('query_id', 'capture_group', 'query_sha256')}
    identities = {key: set() for key in forbidden}
    counts = [dict(tp=0, fp=0, tn=0, fn=0, rejected_negative_queries=0,
                   falsely_rejected_positive_queries=0, queries_with_false_positive=0) for _ in thresholds]
    positive = negative = hits1 = hits5 = candidate_hits = 0
    reciprocal = relevant_candidate_recall = 0.0
    latencies = []
    for row in rows(path, split, pipeline_hash):
        for key in identities:
            identity = row[key]
            if identity in forbidden[key]:
                raise ValueError('Tuning/evaluation leakage: ' + key)
            if key != 'capture_group' and identity in identities[key]:
                raise ValueError('Duplicate query identity or image bytes')
            identities[key].add(identity)
        if len(row['candidates']) > pipeline['k']:
            raise ValueError('Candidate export exceeds configured K')
        relevant = set(row['relevant_skus'])
        # Max per SKU is equivalent to thresholding photos then grouping by SKU.
        best = {}
        for candidate in row['candidates']:
            sku = candidate['sku']
            best[sku] = max(best.get(sku, -math.inf), candidate['final_score'])
        ranked = sorted(best, key=lambda sku: -best[sku])
        wrong = set(best) - relevant
        if relevant:
            positive += 1
            rank = next((i for i, sku in enumerate(ranked) if sku in relevant), None)
            hits1 += rank == 0
            hits5 += rank is not None and rank < 5
            reciprocal += 0 if rank is None else 1 / (rank + 1)
            candidate_hits += bool(relevant & set(best))
            relevant_candidate_recall += len(relevant & set(best)) / len(relevant)
        else:
            negative += 1
        if 'latency_ms' in row:
            latency = row['latency_ms']
            if isinstance(latency, bool) or not isinstance(latency, (int, float)) or not math.isfinite(latency) or latency < 0:
                raise ValueError('Invalid measured latency')
            latencies.append(latency)
        for threshold, count in zip(thresholds, counts):
            returned = set([sku for sku in ranked if best[sku] >= threshold][:pipeline['result_limit']])
            count['tp'] += len(returned & relevant)
            count['fp'] += len(returned - relevant)
            count['fn'] += len(relevant - returned)  # Includes retrieval misses.
            count['tn'] += len(wrong - returned)
            count['queries_with_false_positive'] += bool(returned - relevant)
            count['rejected_negative_queries'] += not relevant and not returned
            count['falsely_rejected_positive_queries'] += bool(relevant) and not bool(returned & relevant)
    total = positive + negative
    if not total:
        raise ValueError('Dataset is empty')
    if split == 'tuning' and (not positive or not negative):
        raise ValueError('Tuning requires positive AND out-of-catalog negative queries')
    if file_hash(path) != source_hash:
        raise ValueError('Dataset changed during evaluation; use a frozen snapshot')
    for threshold, count in zip(thresholds, counts):
        count.update(final_min_score=threshold,
                     precision=divide(count['tp'], count['tp'] + count['fp']),
                     positive_recall=divide(count['tp'], count['tp'] + count['fn']),
                     false_positive_rate=divide(count['fp'], count['fp'] + count['tn']),
                     false_negative_rate=divide(count['fn'], count['fn'] + count['tp']),
                     negative_rejection=divide(count['rejected_negative_queries'], negative),
                     positive_query_false_rejection=divide(count['falsely_rejected_positive_queries'], positive))
    latencies.sort()
    index = (len(latencies) - 1) * .95
    p95 = None if not latencies else latencies[math.floor(index)] + (latencies[math.ceil(index)] - latencies[math.floor(index)]) * (index % 1)
    return dict(schema=1, split=split, pipeline=pipeline, pipeline_sha256=pipeline_hash,
                dataset_sha256=source_hash, identities={k: sorted(v) for k, v in identities.items()},
                queries=total, positive_queries=positive, negative_queries=negative,
                top1=divide(hits1, positive), top5=divide(hits5, positive), mrr=divide(reciprocal, positive),
                recall_at_k=divide(relevant_candidate_recall, positive),
                positive_candidate_hit_rate=divide(candidate_hits, positive), thresholds=counts,
                latency_samples=len(latencies), median_ms=statistics.median(latencies) if latencies else None,
                p95_ms=p95, acceptance_ready=False)


def file_hash(path):
    with Path(path).open('rb') as stream:
        return hashlib.file_digest(stream, 'sha256').hexdigest()


def freeze(report, threshold):
    threshold = score(threshold)
    if report['split'] != 'tuning' or not report['positive_queries'] or not report['negative_queries']:
        raise ValueError('Freeze requires a tuning report with positive AND out-of-catalog queries')
    if threshold not in [r['final_min_score'] for r in report['thresholds']]:
        raise ValueError('Threshold was not tested on tuning')
    if fingerprint(configuration(report['pipeline'])) != report['pipeline_sha256']:
        raise ValueError('Tuning pipeline was modified')
    policy = dict(schema=1, pipeline=report['pipeline'], pipeline_sha256=report['pipeline_sha256'],
                final_min_score=threshold, tuning_dataset_sha256=report['dataset_sha256'],
                tuning_report_sha256=fingerprint(report), tuning_identities=report['identities'],
                acceptance_ready=False)
    policy['integrity_sha256'] = fingerprint(policy)
    return policy


def evaluate_frozen(path, policy, pipeline):
    unsigned = {key: value for key, value in policy.items() if key != 'integrity_sha256'}
    if policy.get('integrity_sha256') != fingerprint(unsigned):
        raise ValueError('Frozen policy was modified')
    current_hash = fingerprint(configuration(pipeline))
    if current_hash != policy['pipeline_sha256'] or current_hash != fingerprint(policy['pipeline']):
        raise ValueError('Frozen policy is incompatible with the current pipeline/catalog')
    result = evaluate(path, pipeline, [policy['final_min_score']], 'evaluation', policy['tuning_identities'])
    result['policy_sha256'] = fingerprint(policy)
    return result


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    sub = parser.add_subparsers(dest='action', required=True)
    tune = sub.add_parser('tune')
    tune.add_argument('dataset', type=Path)
    tune.add_argument('--pipeline', type=Path, required=True)
    tune.add_argument('--thresholds', required=True, help='Explicit comma-separated experimental values; no default')
    freeze_parser = sub.add_parser('freeze')
    freeze_parser.add_argument('report', type=Path)
    freeze_parser.add_argument('--threshold', type=float, required=True)
    heldout = sub.add_parser('evaluate')
    heldout.add_argument('dataset', type=Path)
    heldout.add_argument('--policy', type=Path, required=True)
    heldout.add_argument('--pipeline', type=Path, required=True, help='Configuration used to produce evaluation scores')
    for child in (tune, freeze_parser, heldout):
        child.add_argument('--output', type=Path, required=True)
    args = parser.parse_args()
    try:
        if args.action == 'tune':
            result = evaluate(args.dataset, read_json(args.pipeline), [float(x) for x in args.thresholds.split(',')], 'tuning')
        elif args.action == 'freeze':
            result = freeze(read_json(args.report), args.threshold)
        else:
            result = evaluate_frozen(args.dataset, read_json(args.policy), read_json(args.pipeline))
        # Exclusive create: never silently replace a frozen policy/report.
        with args.output.open('x', encoding='utf-8') as stream:
            json.dump(result, stream, indent=2, allow_nan=False)
        print(f'{args.action}: saved {args.output}; runtime configuration unchanged')
    except (ValueError, KeyError, TypeError, OSError) as error:
        parser.exit(1, str(error) + '\n')


if __name__ == '__main__':
    main()
