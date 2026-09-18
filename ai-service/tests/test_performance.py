"""Performance instrumentation and semantics-preserving skips."""
import json
import subprocess
import sys
import tempfile
import unittest
from dataclasses import replace
from pathlib import Path

import numpy as np
from PIL import Image

from app.config import Settings
from app.search.faiss_index import FaissIndexManager
from app.search.ranking import model_score
from app.search.service import RetrievalService
import test_retrieval as fixtures

MockEncoder = fixtures.MockEncoder

STAGES = ('selection_ms', 'preprocess_ms', 'encode_ms', 'faiss_ms',
          'references_ms', 'ocr_ms', 'text_ms', 'rerank_ms')


class StageInstrumentationTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.root = Path(self.temp.name)
        self.settings = Settings(preprocessing_mode='original', legacy_embed=False)
        encoders = {'siglip': MockEncoder(), 'dino': MockEncoder()}
        self.service = RetrievalService(self.settings, encoders)
        self.index = FaissIndexManager(self.root / 'db.sqlite', self.service.signature)
        self.service.index = self.index
        image = Image.new('RGB', (64, 64), (200, 20, 20))
        vectors, _ = self.service.embed(image, 'original')
        from app.preprocessing.pipeline import photo_hash
        self.index.add_product_embedding({'sku': '001', 'image_id': 'front',
            'product_id': '7', 'category': 'tools', 'photo_hash': photo_hash(image)}, vectors)

    def tearDown(self):
        self.index.close()
        self.temp.cleanup()

    def test_stage_breakdown_is_present_bounded_and_consistent(self):
        result = self.service.search(Image.new('RGB', (64, 64), (200, 20, 20)), mode='original')
        stages = result['stage_ms']
        self.assertEqual(set(stages), set(STAGES))
        for name, value in stages.items():
            self.assertIsInstance(value, float, name)
            self.assertGreaterEqual(value, 0.0, name)
        self.assertLessEqual(sum(stages.values()), result['latency_ms'])
        self.assertGreater(result['latency_ms'], 0.0)

    def test_model_score_endpoints_skip_wasted_work_exactly(self):
        rng = np.random.default_rng(11)
        query = {'global': rng.normal(size=16)}
        reference = {'global': rng.normal(size=16)}
        for key in ('center', 'left'):
            query[key] = rng.normal(size=16)
            reference[key] = rng.normal(size=16)
        norms = lambda d: {k: v / np.linalg.norm(v) for k, v in d.items()}
        query, reference = norms(query), norms(reference)
        global_only = float(np.clip(float(query['global'] @ reference['global']), -1, 1))
        self.assertEqual(model_score(query, reference, 0.0), global_only)
        q = np.stack([query['center'], query['left']])
        r = np.stack([reference['center'], reference['left']])
        similarities = q @ r.T
        local_only = float(np.clip(float((similarities.max(axis=1).mean()
                                          + similarities.max(axis=0).mean()) / 2), -1, 1))
        self.assertEqual(model_score(query, reference, 1.0), local_only)


class BenchmarkScriptTests(unittest.TestCase):
    def test_repeatable_benchmark_reports_stage_shape(self):
        with tempfile.TemporaryDirectory() as temporary:
            output = Path(temporary) / 'bench.json'
            subprocess.run([sys.executable, '-B', '-m', 'app.scripts.benchmark_search',
                            '--references', '8', '--queries', '3', '--seed', 'perf-test',
                            '--output', str(output)],
                           check=True, capture_output=True, cwd=Path(__file__).resolve().parents[1])
            report = json.loads(output.read_text())
            self.assertTrue(report['synthetic'])
            self.assertEqual(report['references'], 8)
            self.assertEqual(set(report['stages']), set(STAGES))
            for stage, values in report['stages'].items():
                self.assertGreaterEqual(values['median_ms'], 0.0, stage)


if __name__ == '__main__':
    unittest.main()
