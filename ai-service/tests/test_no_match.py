"""Confidence/no-match response semantics; no fake calibration."""
import json
import tempfile
import unittest
from dataclasses import replace
from pathlib import Path

import numpy as np
from PIL import Image

from app.config import Settings
from app.search.faiss_index import FaissIndexManager, current_generation
from app.search.service import RetrievalService
import test_text_evidence as fixtures

FixedEncoder = fixtures.FixedEncoder
representations = fixtures.representations


class NoMatchSemanticsTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.root = Path(self.temp.name)
        self.settings = replace(Settings(preprocessing_mode='original', legacy_embed=False),
                                 secondary_weight=0.0, description_text_weight=0.0)
        encoders = {'siglip': FixedEncoder((1., 0.)), 'dino': FixedEncoder((1., 0.))}
        self.service = RetrievalService(self.settings, encoders)
        self.index = FaissIndexManager(self.root / 'db.sqlite', self.service.signature)
        self.service.index = self.index
        self.query = Image.new('RGB', (64, 64), (200, 20, 20))

    def tearDown(self):
        self.index.close()
        self.temp.cleanup()

    def add(self, sku, image_id, global_vec):
        self.index.add_product_embedding(
            {'sku': sku, 'image_id': image_id, 'product_id': sku,
             'category': 'tools', 'photo_hash': 'hash-' + image_id,
             'extra': json.dumps({})},
            representations(global_vec, None))

    def test_uncalibrated_response_marks_heuristic(self):
        self.add('A', 'a', (1., 0.))
        result = self.service.search(self.query, mode='original')
        self.assertFalse(result['confidence_calibrated'])
        self.assertFalse(result['relevance_calibrated'])
        self.assertIn(result['confidence'], ('high', 'medium', 'low'))
        for row in result['results']:
            self.assertGreaterEqual(row['score'], -1.0)
            self.assertLessEqual(row['score'], 1.0)
        self.assertNotIn('probability', json.dumps(result).lower())

    def test_empty_index_returns_low_confidence_safely(self):
        result = self.service.search(self.query, mode='original')
        self.assertEqual(result['results'], [])
        self.assertEqual(result['confidence'], 'low')
        self.assertIsNone(result['score_gap'])
        self.assertFalse(result['ambiguous'])
        self.assertFalse(result['confidence_calibrated'])

    def test_high_confidence_requires_margin_not_just_score(self):
        self.add('A', 'a', (1., 0.))
        self.add('B', 'b', (1., 0.))
        result = self.service.search(self.query, mode='original')
        self.assertAlmostEqual(result['results'][0]['score'], 1.0)
        self.assertAlmostEqual(result['score_gap'], 0.0)
        self.assertNotEqual(result['confidence'], 'high')

    def published_service(self, threshold):
        generation = self.root / 'generation'
        self.index.save_index(generation)
        loaded = FaissIndexManager.load_index(generation)
        try:
            probe = RetrievalService(self.settings, self.service.encoders, loaded)
            policy = {'frozen': True, 'threshold': threshold,
                      'signature': probe.evaluation_signature}
            policy_path = self.root / 'policy.json'
            policy_path.write_text(json.dumps(policy))
            settings = replace(self.settings, relevance_policy=str(policy_path))
            return RetrievalService(settings, self.service.encoders, loaded), loaded
        except Exception:
            loaded.close()
            raise

    def test_calibrated_policy_gates_no_match(self):
        self.add('A', 'a', (1., 0.))
        self.add('B', 'b', (1., 0.))
        # Above any attainable similarity: the gate empties the list honestly.
        service, loaded = self.published_service(threshold=1.5)
        try:
            result = service.search(self.query, mode='original')
            self.assertTrue(result['confidence_calibrated'])
            self.assertTrue(result['relevance_calibrated'])
            self.assertEqual(result['results'], [])
            self.assertEqual(result['confidence'], 'low')
            self.assertFalse(result['ambiguous'])
            self.assertEqual(result['ambiguity_alternatives'], [])
        finally:
            loaded.close()

    def test_calibrated_policy_keeps_above_threshold_hits(self):
        self.add('A', 'a', (1., 0.))
        service, loaded = self.published_service(threshold=0.5)
        try:
            result = service.search(self.query, mode='original')
            self.assertTrue(result['confidence_calibrated'])
            self.assertEqual(result['results'][0]['sku'], 'A')
        finally:
            loaded.close()

    def test_tampered_policy_signature_refuses_to_serve(self):
        self.add('A', 'a', (1., 0.))
        generation = self.root / 'generation'
        self.index.save_index(generation)
        loaded = FaissIndexManager.load_index(generation)
        try:
            policy_path = self.root / 'policy.json'
            policy_path.write_text(json.dumps({'frozen': True, 'threshold': 0.5,
                                               'signature': {'pipeline': 'tampered'}}))
            settings = replace(self.settings, relevance_policy=str(policy_path))
            with self.assertRaises(ValueError):
                RetrievalService(settings, self.service.encoders, loaded)
        finally:
            loaded.close()


if __name__ == '__main__':
    unittest.main()
