"""OCR compatibility as secondary shortlist evidence; vision stays primary."""
import json
import tempfile
import unittest
from dataclasses import replace
from pathlib import Path

import numpy as np
from PIL import Image

from app.config import Settings
from app.encoders.base import ImageEncoder, normalize
from app.features.attributes import VERSION as ATTRIBUTE_VERSION
from app.features.ocr import OcrResult, OcrWord, confidence_weight
from app.search.faiss_index import FaissIndexManager
from app.search.service import RetrievalService, reference_attributes


class FixedEncoder(ImageEncoder):
    identity = {'model': 'mock-ocr', 'revision': 'test-v1', 'pooling': 'fixed'}

    def __init__(self, vector=(1., 0.)):
        self.vector = np.asarray(vector, dtype=np.float32)

    def encode_images(self, images):
        return normalize(np.tile(self.vector, (len(images), 1)))


class StubOCR:
    def __init__(self, words):
        self._words = tuple(words)

    def extract_text(self, image):
        return [word.text for word in self._words]

    def extract_words(self, image):
        return OcrResult(words=self._words, engine='stub')


def word(text, confidence=100.0):
    return OcrWord(text=text, tokens=(text.upper(),), confidence=confidence, box=None)


CROPS = ('global', 'center', 'left', 'right', 'top', 'bottom', 'context')


def representations(global_vec):
    return {name: {crop: np.array(global_vec, dtype=np.float64) for crop in CROPS}
            for name in ('siglip', 'dino')}


class OcrCompatibilityTests(unittest.TestCase):
    def test_trusted_manual_attribute_overrides_parser_without_inventing_vision_labels(self):
        values = reference_attributes({'description': 'PH2 150MM',
            'trusted_attributes': {'drive': ['PH1']}, 'vision_attributes': {'drive': ['PH3']}})
        self.assertEqual(values['drive'], ['PH1'])
        self.assertEqual(values['measurement'], ['150MM'])

    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.root = Path(self.temp.name)
        self.settings = replace(Settings(preprocessing_mode='original', legacy_embed=False),
                                 secondary_weight=0.4, description_text_weight=0.0,
                                 ocr_min_confidence=80)
        encoders = {'siglip': FixedEncoder((1., 0.)), 'dino': FixedEncoder((1., 0.))}
        self.service = RetrievalService(self.settings, encoders)
        self.index = FaissIndexManager(self.root / 'db.sqlite', self.service.signature)
        self.service.index = self.index
        self.query = Image.new('RGB', (64, 64), (200, 20, 20))

    def tearDown(self):
        self.index.close()
        self.temp.cleanup()

    def add(self, sku, image_id, global_vec, description):
        self.index.add_product_embedding(
            {'sku': sku, 'image_id': image_id, 'product_id': sku,
             'category': 'tools', 'photo_hash': 'hash-' + image_id,
             'extra': json.dumps({'description': description})},
            representations(global_vec))

    def search(self, words, **overrides):
        settings = replace(self.settings, **overrides) if overrides else self.settings
        service = RetrievalService(settings, self.service.encoders, self.index,
                                   ocr=StubOCR(words))
        return service.search(self.query, mode='original')

    def test_exact_marking_match_scores_full_confidence(self):
        self.add('A', 'a', (1., 0.), 'Obeng PH2 150MM')
        self.add('B', 'b', (0., 1.), 'Obeng PH1 100MM')
        result = self.search([word('PH2')])
        top = result['results'][0]
        self.assertEqual(top['sku'], 'A')
        self.assertAlmostEqual(top['ocr_score'], 1.0)
        self.assertEqual(result['query']['ocr_tokens'], ['PH2'])

    def test_conflicting_marking_scores_zero_but_keeps_candidate(self):
        # Opposite families (plus vs minus) score zero but keep the candidate.
        self.add('A', 'a', (1., 0.), 'Obeng Minus FLAT 100MM')
        result = self.search([word('PH2')])
        self.assertEqual(result['results'][0]['sku'], 'A')
        self.assertAlmostEqual(result['results'][0]['ocr_score'], 0.0)

    def test_same_family_marking_scores_partial(self):
        # PH2 query vs PH1 reference: same philips family, different code.
        self.add('A', 'a', (1., 0.), 'Obeng PH1 100MM')
        result = self.search([word('PH2')])
        self.assertEqual(result['results'][0]['sku'], 'A')
        self.assertAlmostEqual(result['results'][0]['ocr_score'], 0.5)

    def test_low_confidence_reading_contributes_little(self):
        self.add('A', 'a', (1., 0.), 'Obeng PH2 150MM')
        certain = self.search([word('PH2', 100.0)])
        floor = self.search([word('PH2', 80.0)])
        mid = self.search([word('PH2', 90.0)])
        self.assertAlmostEqual(certain['results'][0]['ocr_score'], 1.0)
        self.assertAlmostEqual(floor['results'][0]['ocr_score'], 0.0)
        self.assertAlmostEqual(mid['results'][0]['ocr_score'], 0.5)

    def test_no_ocr_leaves_visual_ranking_untouched(self):
        self.add('A', 'a', (1., 0.), 'Obeng PH2 150MM')
        self.add('B', 'b', (0., 1.), 'Kuas 2 inch')
        service = RetrievalService(self.settings, self.service.encoders, self.index)
        result = service.search(self.query, mode='original')
        self.assertEqual([r['sku'] for r in result['results']], ['A', 'B'])
        self.assertIsNone(result['results'][0]['ocr_score'])
        self.assertEqual(result['query']['ocr_tokens'], [])

    def test_ambiguous_numeric_token_matches_nothing(self):
        self.add('A', 'a', (1., 0.), 'Obeng PH2 150MM')
        result = self.search([word('1')])
        self.assertIsNone(result['results'][0]['ocr_score'])

    def test_visual_dominance_survives_conflicting_ocr(self):
        self.add('A', 'a', (1., 0.), 'Obeng PH1')
        self.add('B', 'b', (0.8, 0.6), 'Obeng PH2')
        # Tight secondary budget: 0.9*1.0 vs 0.9*0.8 + 0.1*1.0.
        result = self.search([word('PH2')], secondary_weight=0.1)
        self.assertEqual(result['results'][0]['sku'], 'A')

    def test_stored_parse_is_preferred_with_version_fallback(self):
        from app.search.service import reference_attributes
        stored = {'parsed_attributes': {'version': ATTRIBUTE_VERSION,
                                        'values': {'drive': ['PH2']}}, 'description': ''}
        self.assertEqual(reference_attributes(stored), {'drive': ['PH2']})
        stale = {'parsed_attributes': {'version': 'ancient', 'values': {'drive': ['PH9']}},
                 'description': 'Obeng PH2'}
        self.assertEqual(reference_attributes(stale), {'drive': ['PH2']})
        self.assertEqual(reference_attributes({'description': 'Obeng PH2'}), {'drive': ['PH2']})

    def test_confidence_weight_unit(self):
        self.assertAlmostEqual(confidence_weight(100, 80), 1.0)
        self.assertAlmostEqual(confidence_weight(80, 80), 0.0)
        self.assertAlmostEqual(confidence_weight(90, 80), 0.5)
        self.assertEqual(confidence_weight(50, 80), 0.0)
        self.assertEqual(confidence_weight(float('nan'), 80), 0.0)
        self.assertEqual(confidence_weight(100, 100), 1.0)


if __name__ == '__main__':
    unittest.main()
