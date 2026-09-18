"""Repeatable search-pipeline benchmark with deterministic stub encoders.

Measures Python pipeline overhead per stage (selection, preprocess, encode,
FAISS, references, OCR, rerank), NOT real model inference: stub encoders
replace SigLIP/DINO with seeded vectors so results are comparable across
runs and machines. Use liveness/load shape checks, never accuracy claims.
"""
import argparse
import hashlib
import json
import statistics
import tempfile
import time
from pathlib import Path

import numpy as np
from PIL import Image

from ..config import Settings
from ..encoders.base import ImageEncoder, normalize
from ..search.faiss_index import FaissIndexManager
from ..search.service import RetrievalService

STAGES = ('selection_ms', 'preprocess_ms', 'encode_ms', 'faiss_ms',
          'references_ms', 'ocr_ms', 'text_ms', 'rerank_ms')


class StubEncoder(ImageEncoder):
    identity = {'model': 'benchmark-stub', 'revision': 'v1', 'pooling': 'hash'}

    def __init__(self, seed, dimension):
        self.seed = seed
        self.dimension = dimension

    def encode_images(self, images):
        vectors = []
        for image in images:
            digest = hashlib.sha256(self.seed + image.tobytes()).digest()
            rng = np.random.default_rng(int.from_bytes(digest[:8], 'big'))
            vectors.append(rng.normal(size=self.dimension))
        return normalize(np.array(vectors))


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--references', type=int, default=60)
    parser.add_argument('--queries', type=int, default=20)
    parser.add_argument('--seed', type=str, default='bench-v1')
    parser.add_argument('--output', type=Path, required=True)
    args = parser.parse_args()
    if not 1 <= args.references <= 5000 or not 1 <= args.queries <= 500:
        parser.error('Invalid workload bounds')
    settings = Settings(preprocessing_mode='original', legacy_embed=False)
    encoders = {'siglip': StubEncoder(args.seed.encode() + b's', 64),
                'dino': StubEncoder(args.seed.encode() + b'd', 32)}
    service = RetrievalService(settings, encoders)
    with tempfile.TemporaryDirectory(prefix='lensku-bench-') as temporary:
        root = Path(temporary)
        index = FaissIndexManager(root / 'refs.sqlite', service.signature)
        try:
            service.index = index
            rng = np.random.default_rng(int(hashlib.sha256(args.seed.encode()).hexdigest()[:8], 16))
            for i in range(args.references):
                color = tuple(int(v) for v in rng.integers(0, 256, 3))
                image = Image.new('RGB', (64, 64), color)
                vectors, _ = service.embed(image, 'original')
                index.add_product_embedding(
                    {'sku': f'SKU{i % max(1, args.references // 4)}', 'image_id': f'ref-{i}',
                     'product_id': str(i), 'category': 'bench', 'photo_hash': hashlib.sha256(bytes([i % 256])).hexdigest()},
                    vectors)
            per_stage: dict[str, list[float]] = {stage: [] for stage in STAGES}
            totals = []
            for q in range(args.queries):
                color = tuple(int(v) for v in rng.integers(0, 256, 3))
                result = service.search(Image.new('RGB', (64, 64), color), mode='original')
                assert result['results'], 'benchmark index must return candidates'
                totals.append(result['latency_ms'])
                for stage in STAGES:
                    per_stage[stage].append(result['stage_ms'].get(stage, 0.0))
            report = {'synthetic': True,
                      'scope': 'Stub encoders (no model inference); pipeline overhead only',
                      'references': args.references, 'queries': args.queries, 'seed': args.seed,
                      'median_latency_ms': statistics.median(totals),
                      'p95_latency_ms': float(np.percentile(totals, 95)),
                      'stages': {stage: {'median_ms': statistics.median(values),
                                         'p95_ms': float(np.percentile(values, 95))}
                                 for stage, values in per_stage.items()}}
            with args.output.open('x', encoding='utf-8') as output:
                json.dump(report, output, indent=2)
            print(json.dumps(report, indent=2))
        finally:
            index.close()


if __name__ == '__main__':
    main()
