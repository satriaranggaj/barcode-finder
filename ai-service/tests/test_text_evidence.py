"""SigLIP image-description as secondary shortlist evidence, never text-first."""
import tempfile
import unittest
from dataclasses import replace
from pathlib import Path

import numpy as np
from PIL import Image

from app.config import Settings
from app.encoders.base import ImageEncoder, normalize
from app.search.faiss_index import FaissIndexManager
from app.search.service import RetrievalService


class FixedEncoder(ImageEncoder):
    """Deterministic CPU-only encoder; one instance serves image and text."""
    identity = {'model': 'mock-text', 'revision': 'test-v1', 'pooling': 'fixed'}
    text_calls = 0

    def __init__(self, vector=(1., 0.)):
        self.vector = np.asarray(vector, dtype=np.float32)

    def encode_images(self, images):
        return normalize(np.tile(self.vector, (len(images), 1)))

    def encode_text(self, text):
        type(self).text_calls += 1
        return normalize(np.array([0., 1.], dtype=np.float32))


CROPS = ('global', 'center', 'left', 'right', 'top', 'bottom', 'context')


def representations(global_vec, description_vec=None):
    vectors = {}
    for name in ('siglip', 'dino'):
        crops = {crop: np.array(global_vec, dtype=np.float64) for crop in CROPS}
        # Only SigLIP owns a paired text vector; DINO stays purely visual.
        if name == 'siglip' and description_vec is not None:
            crops['description'] = np.array(description_vec, dtype=np.float64)
        vectors[name] = crops
    return vectors


class TextEvidenceTests(unittest.TestCase):
    def setUp(self):
        FixedEncoder.text_calls = 0
        self.temp = tempfile.TemporaryDirectory()
        self.root = Path(self.temp.name)
        self.settings = replace(Settings(preprocessing_mode='original', legacy_embed=False),
                                 description_text_weight=1.0, secondary_weight=0.4)
        encoders = {'siglip': FixedEncoder((1., 0.)), 'dino': FixedEncoder((1., 0.))}
        self.service = RetrievalService(self.settings, encoders)
        self.index = FaissIndexManager(self.root / 'db.sqlite', self.service.signature)
        self.service.index = self.index
        self.query = Image.new('RGB', (64, 64), (200, 20, 20))

    def tearDown(self):
        self.index.close()
        self.temp.cleanup()

    def add(self, sku, image_id, global_vec, description_vec, extra):
        self.index.add_product_embedding(
            {'sku': sku, 'image_id': image_id, 'product_id': sku,
             'category': 'tools', 'photo_hash': 'hash-' + image_id, 'extra': extra},
            representations(global_vec, description_vec))

    def test_empty_description_keeps_visual_ranking(self):
        self.add('A', 'a', (1., 0.), (1., 0.), '{}')
        self.add('B', 'b', (0., 1.), (0., 1.), '{}')
        result = self.service.search(self.query, mode='original')
        self.assertEqual([r['sku'] for r in result['results']], ['A', 'B'])
        self.assertIsNone(result['results'][0]['text_score'])
        self.assertAlmostEqual(result['results'][0]['score'], 1.0)

    def test_matching_description_supports_visual_winner(self):
        import json
        self.add('A', 'a', (1., 0.), (1., 0.), json.dumps({'description': 'Obeng PH2'}))
        self.add('B', 'b', (0., 1.), (0., 1.), json.dumps({'description': 'Kuas 2 inch'}))
        result = self.service.search(self.query, mode='original')
        self.assertEqual(result['results'][0]['sku'], 'A')
        self.assertAlmostEqual(result['results'][0]['text_score'], 1.0)
        self.assertAlmostEqual(result['results'][0]['score'], 1.0)

    def test_conflicting_text_cannot_overrule_strong_visual(self):
        import json
        # Text embeddings deliberately contradict vision: B matches text, A matches vision.
        self.add('A', 'a', (1., 0.), (0., 1.), json.dumps({'description': 'Kuas'}))
        self.add('B', 'b', (0., 1.), (1., 0.), json.dumps({'description': 'Obeng'}))
        result = self.service.search(self.query, mode='original')
        self.assertEqual(result['results'][0]['sku'], 'A')
        self.assertAlmostEqual(result['results'][0]['text_score'], 0.0)
        # Visual majority bounds the damage: 0.6*1 + 0.4*0 > 0.6*0 + 0.4*1.
        self.assertGreater(result['results'][0]['score'], result['results'][1]['score'])

    def test_reference_text_vectors_are_cached_not_recomputed(self):
        import json
        self.add('A', 'a', (1., 0.), (1., 0.), json.dumps({'description': 'Obeng PH2'}))

        class NoTextEncoder(FixedEncoder):
            def encode_text(self, text):
                raise AssertionError('reference text must come from the index, not the encoder')

        encoders = {'siglip': NoTextEncoder((1., 0.)), 'dino': NoTextEncoder((1., 0.))}
        service = RetrievalService(self.settings, encoders, self.index)
        result = service.search(self.query, mode='original')
        # Empty query description short-circuits; reference vectors load from SQLite.
        self.assertAlmostEqual(result['results'][0]['text_score'], 1.0)

    def test_single_encoder_instance_serves_image_and_text_on_cpu(self):
        import json
        self.add('A', 'a', (1., 0.), (1., 0.), json.dumps({'description': 'Obeng PH2'}))
        encoder = self.service.encoders['siglip']
        vectors, _ = self.service.embed(self.query, 'original', description='Obeng PH2')
        self.assertIs(self.service.encoders['siglip'], encoder)
        for vector in vectors['siglip'].values():
            self.assertEqual(vector.dtype, np.float32)
        self.assertGreaterEqual(FixedEncoder.text_calls, 1)


if __name__ == '__main__':
    unittest.main()
