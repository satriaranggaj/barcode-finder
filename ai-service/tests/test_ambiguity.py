"""Ambiguity flags for near-tied candidates; generic, never calibrated."""
import tempfile
import unittest
from dataclasses import replace
from pathlib import Path

import numpy as np
from PIL import Image

from app.config import Settings
from app.search.faiss_index import FaissIndexManager
from app.search.ranking import ambiguity
from app.search.service import RetrievalService
import test_text_evidence as fixtures

FixedEncoder = fixtures.FixedEncoder
representations = fixtures.representations


def row(sku, score, family=None):
    return {'sku': sku, 'score': score, 'image_id': sku.lower(), 'family': family}


class AmbiguityUnitTests(unittest.TestCase):
    def test_near_tie_is_ambiguous_with_alternatives(self):
        report = ambiguity([row('A39', .91, 'swallow'), row('A40', .89, 'swallow'),
                            row('B', .70, 'other')], .05)
        self.assertTrue(report['ambiguous'])
        self.assertEqual(report['reason'], 'same_family_variants')
        self.assertEqual([a['sku'] for a in report['alternatives']], ['A39', 'A40'])

    def test_tie_across_families_reports_near_tie(self):
        report = ambiguity([row('A', .91, 'swallow'), row('B', .90, 'brush')], .05)
        self.assertTrue(report['ambiguous'])
        self.assertEqual(report['reason'], 'near_tie')

    def test_clear_winner_is_not_ambiguous(self):
        report = ambiguity([row('A', .91, 'swallow'), row('B', .80, 'swallow')], .05)
        self.assertFalse(report['ambiguous'])
        self.assertIsNone(report['reason'])
        self.assertEqual([a['sku'] for a in report['alternatives']], ['A'])

    def test_empty_results_are_not_ambiguous(self):
        self.assertEqual(ambiguity([], .05),
                         {'ambiguous': False, 'reason': None, 'alternatives': []})

    def test_margin_validation_and_alternative_cap(self):
        with self.assertRaises(ValueError):
            ambiguity([row('A', .9)], -0.1)
        rows = [row(f'S{i}', .9 - i * .001) for i in range(8)]
        report = ambiguity(rows, .05)
        self.assertTrue(report['ambiguous'])
        self.assertEqual(len(report['alternatives']), 5)


class AmbiguitySearchTests(unittest.TestCase):
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

    def add(self, sku, image_id, global_vec, family=None):
        import json
        self.index.add_product_embedding(
            {'sku': sku, 'image_id': image_id, 'product_id': sku,
             'category': 'tools', 'family': family, 'photo_hash': 'hash-' + image_id,
             'extra': json.dumps({})},
            representations(global_vec, None))

    def test_response_flags_family_variants_and_keeps_top_k(self):
        self.add('SIZE-39', 'a', (1., 0.), 'swallow')
        self.add('SIZE-40', 'b', (0.999, 0.045), 'swallow')
        self.add('OTHER', 'c', (0., 1.), 'brush')
        result = self.service.search(self.query, mode='original', top_k=5)
        self.assertTrue(result['ambiguous'])
        self.assertEqual(result['ambiguity_reason'], 'same_family_variants')
        self.assertEqual([a['sku'] for a in result['ambiguity_alternatives']],
                         ['SIZE-39', 'SIZE-40'])
        self.assertEqual(len(result['results']), 3)
        self.assertEqual(result['results'][0]['sku'], 'SIZE-39')

    def test_response_is_unambiguous_for_clear_winner(self):
        self.add('SIZE-39', 'a', (1., 0.), 'swallow')
        self.add('OTHER', 'c', (0., 1.), 'brush')
        result = self.service.search(self.query, mode='original')
        self.assertFalse(result['ambiguous'])
        self.assertIsNone(result['ambiguity_reason'])

    def test_margin_is_configurable_and_signed(self):
        service = RetrievalService(replace(self.settings, ambiguity_margin=0.5),
                                   self.service.encoders, self.index)
        self.assertEqual(service.evaluation_signature['ambiguity_margin'], 0.5)
        with self.assertRaises(ValueError):
            replace(self.settings, ambiguity_margin=-1)


if __name__ == '__main__':
    unittest.main()
