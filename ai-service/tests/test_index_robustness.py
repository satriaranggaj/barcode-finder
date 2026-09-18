"""Index robustness: failures and identity guards use mocks, never real models."""
import tempfile
import unittest
from pathlib import Path
from unittest.mock import patch

from PIL import Image

from app.config import Settings
from app.search.faiss_index import FaissIndexManager
from app.search.service import RetrievalService
import test_retrieval as fixtures

MockEncoder = fixtures.MockEncoder


class IndexRobustnessTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.root = Path(self.temp.name)
        self.settings = Settings(preprocessing_mode='original', legacy_embed=False)
        self.encoders = {'siglip': MockEncoder(), 'dino': MockEncoder()}
        self.service = RetrievalService(self.settings, self.encoders)
        self.index = FaissIndexManager(self.root / 'db.sqlite', self.service.signature)
        self.service.index = self.index
        self.query = Image.new('RGB', (64, 64), (200, 20, 20))

    def tearDown(self):
        self.index.close()
        self.temp.cleanup()

    def add(self, sku='001', image_id='front', color=(200, 20, 20)):
        from app.preprocessing.pipeline import photo_hash
        image = Image.new('RGB', (64, 64), color)
        vectors, _ = self.service.embed(image, 'original')
        self.index.add_product_embedding({'sku': sku, 'image_id': image_id,
            'product_id': sku, 'category': 'tools', 'photo_hash': photo_hash(image)}, vectors)
        return photo_hash(image)

    def test_all_encoders_failing_raises_without_partial_state(self):
        with patch.object(self.encoders['siglip'], 'encode_images', side_effect=RuntimeError('down')), \
             patch.object(self.encoders['dino'], 'encode_images', side_effect=RuntimeError('down')):
            with self.assertRaisesRegex(RuntimeError, 'No encoder available'):
                self.service.embed(self.query, 'original')

    def test_search_without_index_is_a_service_error(self):
        self.service.index = None
        with self.assertRaisesRegex(RuntimeError, 'Index missing'):
            self.service.search(self.query, mode='original')

    def test_duplicate_image_id_is_rejected_and_original_survives(self):
        digest = self.add()
        image = Image.new('RGB', (64, 64), (20, 200, 20))
        vectors, _ = self.service.embed(image, 'original')
        with self.assertRaisesRegex(ValueError, 'image_id exists'):
            self.index.add_product_embedding({'sku': '002', 'image_id': 'front',
                'product_id': '002', 'category': 'tools', 'photo_hash': digest}, vectors)
        metadata, _ = self.index.reference(0)
        self.assertEqual(metadata['sku'], '001')
        self.assertEqual(self.index.count, 1)

    def test_photo_hash_identity_lookup(self):
        digest = self.add()
        self.assertTrue(self.index.has_photo_hash(digest))
        self.assertFalse(self.index.has_photo_hash('0' * 64))

    def test_near_duplicate_content_is_allowed_as_a_view(self):
        self.add(sku='001', image_id='front', color=(200, 20, 20))
        # Same product, slightly different pixels: a legitimate second view,
        # not an exact duplicate (exact identity is guarded by photo_hash).
        self.add(sku='001', image_id='side', color=(205, 25, 25))
        result = self.service.search(self.query, mode='original')
        self.assertEqual(result['results'][0]['sku'], '001')
        self.assertEqual(result['results'][0]['matched_images'], 2)


if __name__ == '__main__':
    unittest.main()
