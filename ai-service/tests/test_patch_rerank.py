"""Robust symmetric DINO patch reranking on synthetic correspondence fixtures."""
import unittest
from dataclasses import replace
from unittest.mock import patch
import numpy as np
from PIL import Image

from app.config import Settings
from app.search.ranking import patch_similarity, patch_correspondence_scores
from app.search.service import RetrievalService
import app.search.service as search_service
from app.search.faiss_index import FaissIndexManager
import test_retrieval as fixtures

RNG = np.random.default_rng(20260917)


def unit_vectors(count: int, dim: int = 16) -> np.ndarray:
    vectors = RNG.standard_normal((count, dim)).astype(np.float32)
    return vectors / np.linalg.norm(vectors, axis=1, keepdims=True)


class PatchSimilarityTests(unittest.TestCase):
    def test_strong_correspondence_beats_random_correspondence(self):
        query = unit_vectors(8)
        self.assertAlmostEqual(patch_similarity(query, query), 1.0, places=6)
        random_reference = unit_vectors(8)
        self.assertGreater(patch_similarity(query, query),
                           patch_similarity(query, random_reference) + 0.3)

    def test_symmetry_holds_for_all_aggregations_and_unequal_sizes(self):
        a, b = unit_vectors(8), unit_vectors(12)
        for aggregation in ('top_k', 'median', 'trimmed_mean', 'mean'):
            self.assertAlmostEqual(patch_similarity(a, b, aggregation),
                                   patch_similarity(b, a, aggregation), places=6, msg=aggregation)
        small, large = unit_vectors(4), np.vstack([unit_vectors(4), unit_vectors(60)])
        self.assertAlmostEqual(patch_similarity(small, large),
                               patch_similarity(large, small), places=6)

    def test_outlier_patch_does_not_dominate(self):
        query = unit_vectors(16)
        with_outlier = np.vstack([query, -query[0]])
        self.assertAlmostEqual(patch_similarity(query, with_outlier),
                               patch_similarity(query, query), places=6)
        # A mean over every correspondence is the fragile baseline; the robust
        # default must beat it on contaminated evidence.
        self.assertLess(patch_similarity(query, with_outlier, aggregation='mean'),
                        patch_similarity(query, query))

    def test_masking_excludes_background_patches(self):
        content = unit_vectors(8)
        mask = np.array([True] * 8 + [False] * 4)
        query = np.vstack([content, unit_vectors(4)])
        reference = np.vstack([content, unit_vectors(4)])
        masked = patch_similarity(query, reference, query_mask=mask, reference_mask=mask)
        self.assertAlmostEqual(masked, patch_similarity(content, content), places=6)
        self.assertLess(patch_similarity(query, reference), masked)
        for bad_mask in (np.ones(5, bool), np.ones(12, int), np.ones((12, 1), bool)):
            with self.assertRaisesRegex(ValueError, 'mask'):
                patch_similarity(query, reference, query_mask=bad_mask)

    def test_reference_patch_count_does_not_change_score(self):
        query = unit_vectors(4)
        eight = np.vstack([query, unit_vectors(4)])
        sixty_four = np.vstack([query, unit_vectors(60)])
        self.assertAlmostEqual(patch_similarity(query, eight),
                               patch_similarity(query, sixty_four), places=6)
        self.assertLess(patch_similarity(query, sixty_four, aggregation='mean'),
                        patch_similarity(query, sixty_four))

    def test_correspondence_scores_expose_directions_for_debug(self):
        query = unit_vectors(8)
        details = patch_correspondence_scores(query, query)
        self.assertEqual(set(details), {'query_to_reference', 'reference_to_query',
                                        'symmetric', 'query_patches', 'reference_patches'})
        self.assertAlmostEqual(details['symmetric'],
                               (details['query_to_reference'] + details['reference_to_query']) / 2)
        self.assertEqual(details['query_patches'], 8)
        self.assertEqual(details['reference_patches'], 8)

    def test_invalid_evidence_and_aggregation_rejected(self):
        with self.assertRaisesRegex(ValueError, 'At least four'):
            patch_similarity(unit_vectors(3), unit_vectors(3))
        with self.assertRaisesRegex(ValueError, 'aggregation'):
            patch_similarity(unit_vectors(4), unit_vectors(4), aggregation='bogus')
        with self.assertRaisesRegex(ValueError, 'trim'):
            patch_similarity(unit_vectors(4), unit_vectors(4), aggregation='trimmed_mean', trim=0.5)
        with self.assertRaises(ValueError):
            Settings(patch_aggregation='bogus')
        with self.assertRaises(ValueError):
            Settings(patch_top_k=2)
        with self.assertRaises(ValueError):
            Settings(patch_trim=0.5)


class PatchRerankServiceTests(fixtures.RetrievalTests):
    REF_A = np.array([[1., 0., 0.], [0., 1., 0.], [0., 0., 1.], [-1., 0., 0.]], dtype=np.float32)
    REF_B = np.array([[1., 1., 0.], [0., 1., 1.], [1., 0., 1.], [1., 1., 1.]], dtype=np.float32)
    REF_B = REF_B / np.linalg.norm(REF_B, axis=1, keepdims=True)

    class FixedPatches(fixtures.MockEncoder):
        def __init__(self, patches):
            self.patch_set = np.asarray(patches, dtype=np.float32)

        def encode_patches(self, image, limit):
            return self.patch_set

    def _build(self, settings, rows):
        """Identical global vectors everywhere; only patch detail differs per SKU."""
        writer = RetrievalService(settings, {'siglip': fixtures.MockEncoder(),
                                             'dino': self.FixedPatches(self.REF_A)})
        index = FaissIndexManager(self.root / 'rerank.sqlite', writer.signature)
        image = Image.new('RGB', (64, 64), (200, 20, 20))
        for sku, image_id, patches in rows:
            vectors, _ = writer.embed(image, 'original')
            if settings.patch_weight > 0:
                vectors['dino']['patches'] = np.asarray(patches, dtype=np.float32)
            index.add_product_embedding({'sku': sku, 'image_id': image_id,
                                         'product_id': sku, 'photo_hash': f'{sku}-{image_id}'}, vectors)
        return writer, index

    def test_fine_detail_reranks_shortlist_and_exposes_local_score(self):
        settings = replace(self.settings, patch_weight=0.4)
        writer, index = self._build(settings, [('PH1', 'one', self.REF_A), ('PH2', 'two', self.REF_B)])
        try:
            querier = RetrievalService(settings, {'siglip': fixtures.MockEncoder(),
                                                  'dino': self.FixedPatches(self.REF_A)}, index)
            with patch('app.search.service.patch_similarity',
                       wraps=search_service.patch_similarity) as tracked, \
                 patch.object(index, 'references', wraps=index.references) as bulk:
                result = querier.search(Image.new('RGB', (64, 64), (200, 20, 20)), mode='original')
            self.assertEqual(result['results'][0]['sku'], 'PH1')
            self.assertEqual(result['candidate_images'], 2)
            # Patch correspondence only ever runs on the bounded shortlist.
            self.assertEqual(tracked.call_count, result['candidate_images'])
            self.assertEqual(bulk.call_count, 1)
            self.assertEqual(len(bulk.call_args[0][0]), result['candidate_images'])
            self.assertGreater(result['results'][0]['local_score'], 0.9)
            self.assertGreater(result['results'][0]['local_score'], result['results'][1]['local_score'])
        finally:
            index.close()

    def test_more_references_do_not_win_by_count(self):
        settings = replace(self.settings, patch_weight=0.4)
        writer, index = self._build(settings, [('PH1', 'one', self.REF_A),
                                               ('PH2', 'two-a', self.REF_B),
                                               ('PH2', 'two-b', self.REF_B)])
        try:
            querier = RetrievalService(settings, {'siglip': fixtures.MockEncoder(),
                                                  'dino': self.FixedPatches(self.REF_A)}, index)
            result = querier.search(Image.new('RGB', (64, 64), (200, 20, 20)), mode='original')
            self.assertEqual(result['results'][0]['sku'], 'PH1')
            self.assertEqual(result['results'][1]['sku'], 'PH2')
            self.assertEqual(result['candidate_images'], 3)
            self.assertEqual(result['results'][1]['matched_images'], 2)
        finally:
            index.close()

    def test_patch_weight_zero_keeps_scores_global_only(self):
        rows = [('PH1', 'one', self.REF_A), ('PH2', 'two', self.REF_B)]
        writer, index = self._build(self.settings, rows)
        try:
            querier = RetrievalService(self.settings, {'siglip': fixtures.MockEncoder(),
                                                       'dino': fixtures.MockEncoder()}, index)
            result = querier.search(Image.new('RGB', (64, 64), (200, 20, 20)), mode='original')
            self.assertEqual(result['candidate_images'], 2)
            for row in result['results']:
                self.assertIsNone(row['local_score'])
        finally:
            index.close()


if __name__ == '__main__':
    unittest.main()
