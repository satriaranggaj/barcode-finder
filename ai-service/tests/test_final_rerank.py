"""Unified final reranker contract: visual-majority fusion, all weights signed."""
import json
import tempfile
import unittest
from dataclasses import replace
from pathlib import Path

import numpy as np
from PIL import Image

from app.config import Settings
from app.search.faiss_index import FaissIndexManager
from app.search.service import RetrievalService
import test_ocr_compatibility as fixtures
import test_text_evidence as text_fixtures

FixedEncoder = fixtures.FixedEncoder
StubOCR = fixtures.StubOCR
word = fixtures.word
representations = text_fixtures.representations


class FinalRerankTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.root = Path(self.temp.name)
        self.base = replace(Settings(preprocessing_mode='original', legacy_embed=False),
                            secondary_weight=0.4, description_text_weight=0.5,
                            ocr_min_confidence=80)
        encoders = {'siglip': FixedEncoder((1., 0.)), 'dino': FixedEncoder((1., 0.))}
        self.service = RetrievalService(self.base, encoders)
        self.index = FaissIndexManager(self.root / 'db.sqlite', self.service.signature)
        self.service.index = self.index
        self.query = Image.new('RGB', (64, 64), (200, 20, 20))

    def tearDown(self):
        self.index.close()
        self.temp.cleanup()

    def add(self, sku, image_id, global_vec, extra, description_vec='same'):
        if description_vec == 'same':
            description_vec = global_vec
        self.index.add_product_embedding(
            {'sku': sku, 'image_id': image_id, 'product_id': sku,
             'category': 'tools', 'photo_hash': 'hash-' + image_id,
             'extra': json.dumps(extra)},
            representations(global_vec, description_vec))

    def search(self, ocr_words=(), **overrides):
        settings = replace(self.base, **overrides) if overrides else self.base
        service = RetrievalService(settings, self.service.encoders, self.index,
                                   ocr=StubOCR(list(ocr_words)))
        return service.search(self.query, mode='original')

    def test_missing_secondary_signals_keep_pure_visual_score(self):
        self.add('A', 'a', (1., 0.), {})
        self.add('B', 'b', (0., 1.), {})
        result = self.search()
        top = result['results'][0]
        self.assertEqual(top['sku'], 'A')
        self.assertAlmostEqual(top['score'], top['visual_score'])
        self.assertIsNone(top['ocr_score'])
        self.assertIsNone(top['text_score'])
        for field in ('siglip_score', 'dino_score', 'global_score'):
            self.assertIsNotNone(top[field])

    def test_all_signals_combine_with_visual_majority(self):
        self.add('A', 'a', (1., 0.),
                 {'description': 'Obeng PH2'}, (1., 0.))
        self.add('B', 'b', (0., 1.),
                 {'description': 'Kuas 2 inch'}, (0., 1.))
        result = self.search([word('PH2')])
        top = result['results'][0]
        self.assertEqual(top['sku'], 'A')
        self.assertAlmostEqual(top['text_score'], 1.0)
        self.assertAlmostEqual(top['ocr_score'], 1.0)
        # 0.6*visual(1.0) + 0.4*secondary(1.0).
        self.assertAlmostEqual(top['score'], 1.0)
        self.assertGreaterEqual(top['visual_score'], 0.6 * top['score'])

    def test_visual_is_monotone_and_secondary_influence_is_bounded(self):
        self.add('A', 'a', (1., 0.), {'description': 'Kuas'})
        self.add('B', 'b', (0.8, 0.6), {'description': 'Obeng PH2'})
        # Same conflicting secondary for a visual sweep: order follows vision.
        result = self.search([word('PH2')])
        order = [r['sku'] for r in result['results']]
        self.assertEqual(order, ['A', 'B'])
        for row in result['results']:
            self.assertLessEqual(abs(row['score'] - row['visual_score']), 2 * 0.4)

    def test_disabled_secondary_weights_yield_visual_only(self):
        self.add('A', 'a', (1., 0.), {'description': 'Obeng PH2'})
        result = self.search([word('PH2')], secondary_weight=0.0)
        self.assertAlmostEqual(result['results'][0]['score'],
                               result['results'][0]['visual_score'])

    def test_weight_configuration_is_validated(self):
        for kwargs in ({'secondary_weight': 0.5}, {'secondary_weight': -0.1},
                       {'description_text_weight': 1.5}, {'description_text_weight': -0.1},
                       {'local_weight': 1.5}, {'siglip_weight': 0.5}):
            with self.assertRaises(ValueError, msg=str(kwargs)):
                replace(self.base, **kwargs)

    def test_full_response_is_deterministic(self):
        import json as jsonlib
        self.add('A', 'a', (1., 0.), {'description': 'Obeng PH2'})
        self.add('B', 'b', (0., 1.), {'description': 'Kuas'})
        first = self.search([word('PH2')])
        second = self.search([word('PH2')])
        self.assertEqual(jsonlib.dumps(first['results'], sort_keys=True, default=str),
                         jsonlib.dumps(second['results'], sort_keys=True, default=str))

    def test_reference_count_never_raises_sku_score(self):
        for i in range(3):
            self.add('MANY', f'm{i}', (1., 0.), {})
        self.add('FEW', 'f', (1., 0.), {})
        result = self.search()
        scores = {r['sku']: r['score'] for r in result['results']}
        self.assertAlmostEqual(scores['MANY'], scores['FEW'])
        many = next(r for r in result['results'] if r['sku'] == 'MANY')
        self.assertEqual(many['matched_images'], 3)

    def test_evaluation_signature_records_every_weight(self):
        signature = self.service.evaluation_signature
        for key in ('weights', 'local_weight', 'global_weight', 'patch_weight',
                    'secondary_weight', 'description_text_weight', 'candidates',
                    'ranking_code'):
            self.assertIn(key, signature)
        self.assertEqual(signature['weights'], {'siglip': 0.35, 'dino': 0.65})


if __name__ == '__main__':
    unittest.main()
