"""DINO patch-token extraction: shapes, normalization, padding mask, degradation."""
import contextlib
import unittest
from dataclasses import replace
import numpy as np
from PIL import Image

from app.encoders.dino import DinoEncoder, PatchFeatures, spatial_validity
from app.search.faiss_index import FaissIndexManager
from app.search.service import RetrievalService
import test_retrieval as fixtures

GRID = 8
DIM = 16


class FakeTensor(np.ndarray):
    """numpy stand-in for torch.Tensor with the three methods the encoder calls."""
    def float(self):
        return self.astype(np.float32)

    def cpu(self):
        return self

    def numpy(self):
        return np.asarray(self)


def fake_dino(token_count: int = 1 + GRID * GRID):
    encoder = DinoEncoder.__new__(DinoEncoder)
    encoder.device = 'cpu'
    encoder.kind = 'cls'
    encoder.tokenizer = None
    encoder.processor = lambda images, return_tensors, do_center_crop: {}
    encoder.torch = type('Torch', (), {'inference_mode': staticmethod(contextlib.nullcontext)})()
    tokens = np.arange(token_count * DIM, dtype=np.float32).reshape(1, token_count, DIM) / 1000

    def model(**inputs):
        result = type('Output', (), {})()
        result.last_hidden_state = tokens.view(FakeTensor)
        return result

    encoder.model = model
    return encoder


class PatchFeatureTests(unittest.TestCase):
    def test_spatial_validity_square_portrait_landscape(self):
        np.testing.assert_array_equal(spatial_validity((100, 100), 100, GRID), np.ones(GRID * GRID, bool))
        portrait = spatial_validity((100, 200), 200, GRID).reshape(GRID, GRID)
        self.assertTrue(portrait[:, 2:6].all())
        self.assertFalse(portrait[:, :2].any())
        self.assertFalse(portrait[:, 6:].any())
        self.assertEqual(portrait.sum(), 32)
        landscape = spatial_validity((200, 100), 200, GRID).reshape(GRID, GRID)
        self.assertTrue(landscape[2:6, :].all())
        self.assertFalse(landscape[:2, :].any())
        self.assertFalse(landscape[6:, :].any())
        self.assertEqual(landscape.sum(), 32)

    def test_patch_features_shape_normalization_and_mask(self):
        result = fake_dino().patch_features(Image.new('RGB', (100, 200)))
        self.assertIsInstance(result, PatchFeatures)
        self.assertEqual(result.grid, GRID)
        self.assertEqual(result.features.shape, (GRID * GRID, DIM))
        self.assertEqual(result.features.dtype, np.float32)
        self.assertEqual(result.valid.dtype, np.bool_)
        self.assertEqual(result.valid.shape, (GRID * GRID,))
        self.assertEqual(result.valid.sum(), 32)
        np.testing.assert_allclose(np.linalg.norm(result.features, axis=-1), 1, rtol=1e-6)
        np.testing.assert_array_equal(result.content, result.features[result.valid])
        self.assertEqual(result.content.shape, (32, DIM))

    def test_portrait_landscape_and_tiny_square_images(self):
        for size in ((100, 200), (200, 100), (8, 8)):
            result = fake_dino().patch_features(Image.new('RGB', size))
            self.assertEqual(result.valid.sum(), 32 if size != (8, 8) else GRID * GRID)

    def test_too_narrow_image_rejected_without_semantic_parts(self):
        with self.assertRaisesRegex(ValueError, 'narrow'):
            fake_dino().patch_features(Image.new('RGB', (64, 2)))

    def test_non_square_token_layout_rejected(self):
        with self.assertRaisesRegex(ValueError, 'layout'):
            fake_dino(token_count=1 + 63).patch_features(Image.new('RGB', (100, 100)))

    def test_repeat_calls_are_bitwise_deterministic(self):
        encoder = fake_dino()
        image = Image.new('RGB', (100, 200))
        first, second = encoder.patch_features(image), encoder.patch_features(image)
        np.testing.assert_array_equal(first.features, second.features)
        np.testing.assert_array_equal(first.valid, second.valid)
        np.testing.assert_array_equal(first.compact(20), second.compact(20))

    def test_compact_caps_by_even_grid_sampling(self):
        result = fake_dino().patch_features(Image.new('RGB', (100, 200)))
        self.assertEqual(result.compact(10).shape, (10, DIM))
        self.assertEqual(result.compact(100).shape, (32, DIM))
        expected = result.content[np.linspace(0, 31, 10, dtype=int)]
        np.testing.assert_array_equal(result.compact(10), expected)
        with self.assertRaisesRegex(ValueError, 'at least 1'):
            result.compact(0)

    def test_encode_patches_matches_compact_content(self):
        encoder = fake_dino()
        image = Image.new('RGB', (100, 200))
        np.testing.assert_array_equal(encoder.encode_patches(image, 64),
                                      encoder.patch_features(image).compact(64))
        self.assertEqual(encoder.encode_patches(image, 5).shape, (5, DIM))

    def test_dataclass_rejects_inconsistent_shapes(self):
        for features, valid, grid in ((np.zeros((5, DIM)), np.ones(GRID * GRID, bool), GRID),
                                      (np.zeros((GRID * GRID, DIM)), np.ones(5, bool), GRID)):
            with self.assertRaisesRegex(ValueError, 'PatchFeatures'):
                PatchFeatures(features, valid, grid)
        coerced = PatchFeatures(np.zeros((GRID * GRID, DIM)), np.ones(GRID * GRID, dtype=int), GRID)
        self.assertEqual(coerced.valid.dtype, np.bool_)


class PatchServiceTests(fixtures.RetrievalTests):
    def test_patch_failure_keeps_dino_global_representation(self):
        class BrokenPatches(fixtures.MockEncoder):
            def encode_patches(self, image, limit):
                raise RuntimeError('patch backend offline')

        service = RetrievalService(replace(self.settings, patch_weight=0.2), {'dino': BrokenPatches()})
        with self.assertLogs('app.search.service', level='ERROR'):
            query, _ = service.embed(Image.new('RGB', (64, 64)), 'original')
        self.assertIn('dino', query)
        self.assertIn('global', query['dino'])
        self.assertNotIn('patches', query['dino'])

    def test_missing_patch_support_keeps_dino_global_representation(self):
        service = RetrievalService(replace(self.settings, patch_weight=0.2),
                                   {'dino': fixtures.MockEncoder()})
        with self.assertLogs('app.search.service', level='ERROR'):
            query, _ = service.embed(Image.new('RGB', (64, 64)), 'original')
        self.assertIn('global', query['dino'])
        self.assertNotIn('patches', query['dino'])

    def test_search_skips_patch_rerank_when_query_patches_missing(self):
        class WorkingPatches(fixtures.MockEncoder):
            def encode_patches(self, image, limit):
                return np.tile(self.encode_image(image), (8, 1))

        class BrokenPatches(fixtures.MockEncoder):
            def encode_patches(self, image, limit):
                raise RuntimeError('patch backend offline')

        settings = replace(self.settings, patch_weight=0.2)
        writer = RetrievalService(settings, {'siglip': fixtures.MockEncoder(), 'dino': WorkingPatches()})
        index = FaissIndexManager(self.root / 'db.sqlite', writer.signature)
        try:
            vectors, _ = writer.embed(Image.new('RGB', (64, 64)), 'original')
            index.add_product_embedding({'sku': 'A', 'image_id': 'a', 'photo_hash': 'a' * 64}, vectors)
            query_service = RetrievalService(settings, {'siglip': fixtures.MockEncoder(),
                                                        'dino': BrokenPatches()}, index)
            with self.assertLogs('app.search.service', level='ERROR'), \
                 fixtures.patch('app.search.service.patch_similarity',
                                side_effect=AssertionError('patch rerank must not run')):
                result = query_service.search(Image.new('RGB', (64, 64)), mode='original')
            self.assertEqual(result['results'][0]['sku'], 'A')
        finally:
            index.close()


if __name__ == '__main__':
    unittest.main()
