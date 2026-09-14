"""Held-out evaluation; no parameter/threshold tuning occurs in this command."""
import argparse
import json
import logging
from pathlib import Path
import numpy as np
from ..config import Settings
from ..preprocessing.pipeline import photo_hash
from ..search.service import RetrievalService, load_encoders
from ..search.faiss_index import FaissIndexManager, current_generation
from .dataset import iter_images, read_photo


def evaluate(dataset: Path, service: RetrievalService) -> dict:
    if service.index is None or not service.index.count:
        raise ValueError('Evaluation requires a nonempty reference index')
    identities = set()
    for _, path in iter_images(dataset):
        identity = photo_hash(read_photo(path, service.settings))
        if identity in identities or service.index.has_photo_hash(identity):
            raise ValueError(f'Duplicate/reference leakage: {path.name}')
        identities.add(identity)
    if not identities:
        raise ValueError('Evaluation contains no supported images')
    hits = {1: 0, 3: 0, 5: 0}
    reciprocal, latency, rows = [], [], []
    for sku, path in iter_images(dataset):
        result = service.search(read_photo(path, service.settings), 50, mode=service.settings.preprocessing_mode)
        rank = next((i + 1 for i, row in enumerate(result['results']) if row['sku'] == sku), None)
        for k in hits:
            hits[k] += int(rank is not None and rank <= k)
        reciprocal.append(1/rank if rank else 0)
        latency.append(result['latency_ms'])
        rows.append({'query': path.relative_to(dataset).as_posix(), 'expected_sku': sku,
                     'rank': rank, 'results': result['results'], 'latency_ms': result['latency_ms']})
        if len(rows) % 25 == 0:
            print(f'Evaluated {len(rows)}', flush=True)
    return {'images_tested': len(rows), **{f'top_{k}_accuracy': value/len(rows) for k, value in hits.items()},
            'mrr': float(np.mean(reciprocal)), 'mrr_cutoff': 50, 'median_latency_ms': float(np.median(latency)),
            'p95_latency_ms': float(np.percentile(latency, 95)), 'pipeline': service.index.signature,
            'weights': service.settings.weights, 'local_weight': service.settings.local_weight,
            'queries': rows}


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
        print(json.dumps({key: value for key, value in report.items() if key != 'queries'}, indent=2))
    finally:
        index.close()


if __name__ == '__main__':
    main()
