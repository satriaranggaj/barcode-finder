"""Object-centric representation: object pairs are primary, global pairs fall back.

Mixed pairs (object query vs full reference or the reverse) are never compared
directly; both sides share the global (full-image) representation instead.
"""
import json
import unittest
from dataclasses import replace
from PIL import Image
from app.config import Settings
from app.preprocessing.selection import BoundingBox
from app.search.faiss_index import FaissIndexManager
from app.search.ranking import fuse_ranks, model_score, weighted_score
from app.search.service import RetrievalService, reference_has_object, is_object_box, proportion_compatibility
import test_retrieval as fixtures
MockEncoder = fixtures.MockEncoder


class ObjectCentricTests(unittest.TestCase):
    setUp = fixtures.RetrievalTests.setUp
    tearDown = fixtures.RetrievalTests.tearDown

    def half_image(self):
        image = Image.new('RGB', (64, 64), (10, 10, 200))
        image.paste((200, 10, 10), (0, 0, 32, 64))
        return image

    def add_object_ref(self, image, box, sku='001', image_id='one'):
        vectors, _ = self.service.embed(image, 'object', box)
        self.index.add_product_embedding({'sku': sku, 'image_id': image_id,
            'photo_hash': f'h-{image_id}',
            'representation': 'object', 'selection_source': 'manual',
            'extra': json.dumps({'crop': {'x': box.x, 'y': box.y, 'width': box.width, 'height': box.height},
                                 'selection_source': 'manual'})}, vectors)

    def add_full_ref(self, image, sku='002', image_id='two'):
        vectors, _ = self.service.embed(image, 'original')
        self.index.add_product_embedding({'sku': sku, 'image_id': image_id,
                                          'photo_hash': f'h-{image_id}'}, vectors)

    def test_object_pair_is_primary_and_matches_manual_scores(self):
        image, box = self.half_image(), BoundingBox(0, 0, .5, 1)
        self.add_object_ref(image, box)
        query, _ = self.service.embed(image, 'object', box)
        result = self.service.search(image, 5, None, 'object', box)
        row = result['results'][0]
        self.assertEqual(row['sku'], '001')
        self.assertIsNotNone(row['object_score'])
        self.assertIsNotNone(row['global_score'])
        self.assertTrue(result['query']['object_representation'])
        reference = self.index.reference(0)[1]
        expected = weighted_score({name: model_score(query[name], reference[name], self.settings.local_weight)
                                   for name in self.service.encoders}, self.settings.weights)
        self.assertAlmostEqual(row['object_score'], expected, places=6)
        self.assertAlmostEqual(row['visual_score'], expected, places=6)  # global_weight=0

    def test_global_blend_weight_is_configurable(self):
        image, box = self.half_image(), BoundingBox(0, 0, .5, 1)
        self.add_object_ref(image, box)
        blended = RetrievalService(replace(self.settings, global_weight=.5), self.encoders, self.index)
        row = blended.search(image, 5, None, 'object', box)['results'][0]
        self.assertAlmostEqual(row['visual_score'],
                               .5 * row['object_score'] + .5 * row['global_score'], places=6)

    def test_object_query_vs_full_reference_never_compares_directly(self):
        image, box = self.half_image(), BoundingBox(0, 0, .5, 1)
        self.add_full_ref(image)  # no crop metadata: representation is the full image
        row = self.service.search(image, 5, None, 'object', box)['results'][0]
        self.assertIsNone(row['object_score'])
        # Identical full images: the global pair scores 1 while the deprecated
        # object-vs-full comparison (red half vs mixed mean) would score far below.
        self.assertAlmostEqual(row['global_score'], 1.0, places=5)
        self.assertAlmostEqual(row['score'], 1.0, places=5)

    def test_full_query_vs_object_reference_falls_back_to_global(self):
        image, box = self.half_image(), BoundingBox(0, 0, .5, 1)
        self.add_object_ref(image, box)
        row = self.service.search(image, 5, None, 'original')['results'][0]
        self.assertIsNone(row['object_score'])
        self.assertAlmostEqual(row['global_score'], 1.0, places=5)
        self.assertEqual(row['sku'], '001')

    def test_reference_object_detection_rules(self):
        self.assertFalse(reference_has_object({}))
        self.assertFalse(reference_has_object({'crop': None}))
        self.assertFalse(reference_has_object({'crop': 'legacy'}))
        self.assertFalse(reference_has_object({'crop': {'x': 0, 'y': 0, 'width': 1, 'height': 1}}))
        self.assertFalse(reference_has_object({'crop': {'x': .5, 'y': 0, 'width': 1, 'height': 1}}))
        self.assertTrue(reference_has_object({'crop': {'x': 0, 'y': 0, 'width': .5, 'height': 1}}))
        self.assertTrue(reference_has_object({'crop': {'x': .1, 'y': .1, 'width': .8, 'height': .8}}))
        self.assertFalse(is_object_box(None))
        self.assertTrue(is_object_box(BoundingBox(0, 0, .5, 1)))
        self.assertFalse(is_object_box(BoundingBox(0, 0, 1, 1)))

    def test_embed_reports_object_representation_flag(self):
        image = Image.new('RGB', (64, 64), 'red')
        _, info = self.service.embed(image, 'object', BoundingBox(0, 0, .5, 1))
        self.assertTrue(info['object_representation'])
        _, info = self.service.embed(image, 'original')
        self.assertFalse(info['object_representation'])
        _, info = self.service.embed(image, 'object', BoundingBox(0, 0, 1, 1))
        self.assertFalse(info['object_representation'])

    def test_signature_version_mismatch_fails_clearly(self):
        self.assertEqual(self.service.signature['preprocessing']['version'], 'object-centric-v4')
        forged_signature = json.loads(json.dumps(self.service.signature))
        forged_signature['preprocessing']['version'] = 'selected-object-v2'
        forged = FaissIndexManager(self.root / 'forged.sqlite', forged_signature)
        try:
            with self.assertRaisesRegex(ValueError, 'rebuild index'):
                RetrievalService(self.settings, self.encoders, forged)
        finally:
            forged.close()

    def test_rank_fusion_is_configurable_and_validated(self):
        shortlists = {'siglip': [(0, .9), (1, .8)], 'dino': [(1, .7), (0, .6)]}
        self.assertEqual(fuse_ranks(shortlists, {'siglip': .5, 'dino': .5}, 0), {0: .75, 1: .75})
        biased = fuse_ranks(shortlists, {'siglip': .8, 'dino': .2}, 3)
        self.assertAlmostEqual(biased[0], .8 / 4 + .2 / 5)
        self.assertAlmostEqual(biased[1], .8 / 5 + .2 / 4)
        self.assertEqual(Settings(rank_bias=0).rank_bias, 0.0)
        with self.assertRaises(ValueError):
            Settings(rank_bias=-1)
        with self.assertRaises(ValueError):
            Settings(rank_bias=float('nan'))

    def test_tip_detail_weight_is_validated(self):
        self.assertEqual(Settings(tip_detail_weight=0).tip_detail_weight, 0.0)
        for bad in ({'tip_detail_weight': -0.1}, {'tip_detail_weight': 1.5}):
            with self.assertRaises(ValueError):
                Settings(**bad)

    def test_proportion_weight_is_validated(self):
        self.assertEqual(Settings(proportion_weight=0).proportion_weight, 0.0)
        for bad in ({'proportion_weight': -0.1}, {'proportion_weight': 0.5}):
            with self.assertRaises(ValueError):
                Settings(**bad)

    def test_proportion_compatibility_rules(self):
        same = {'x': .3, 'y': .1, 'width': .3, 'height': .75}
        self.assertAlmostEqual(proportion_compatibility(dict(same), dict(same)), 1.0)
        # 4" vs 6" proportions differ measurably but stay plausible.
        longer = {'x': .3, 'y': .05, 'width': .26, 'height': .9}
        mid = proportion_compatibility(dict(same), dict(longer))
        self.assertGreater(mid, 0.0)
        self.assertLess(mid, 1.0)
        # Square vs strip: near zero.
        self.assertLess(proportion_compatibility(
            {'x': 0, 'y': 0, 'width': .5, 'height': .5},
            {'x': 0, 'y': 0, 'width': .1, 'height': .9}), 0.2)
        # Full-image boxes and malformed crops never contribute.
        self.assertIsNone(proportion_compatibility(
            {'x': 0, 'y': 0, 'width': 1, 'height': 1}, dict(same)))
        self.assertIsNone(proportion_compatibility(dict(same), None))
        self.assertIsNone(proportion_compatibility(dict(same), {'x': 0}))
        self.assertIsNone(proportion_compatibility(None, dict(same)))

    def test_tip_detail_evidence_only_for_object_pairs(self):
        settings = replace(self.settings, tip_detail_weight=.25)
        service = RetrievalService(settings, self.encoders)
        index = FaissIndexManager(self.root / 'tip.sqlite', service.signature)
        try:
            image, box = self.half_image(), BoundingBox(0, 0, .5, 1)
            vectors, _ = service.embed(image, 'object', box)
            self.assertIn('tip', vectors['siglip'])
            self.assertIn('tip', vectors['dino'])
            # Disabled by default: identical call without the weight emits nothing extra.
            plain, _ = self.service.embed(image, 'object', box)
            self.assertNotIn('tip', plain['siglip'])
            self.assertNotIn('tip', plain['dino'])
            # Full images carry no detail end, even with the weight on.
            full, _ = service.embed(image, 'original')
            self.assertNotIn('tip', full['siglip'])
            index.add_product_embedding({'sku': '001', 'image_id': 'one',
                'photo_hash': 'h-one', 'representation': 'object', 'selection_source': 'manual',
                'extra': json.dumps({'crop': {'x': box.x, 'y': box.y, 'width': box.width, 'height': box.height},
                                     'selection_source': 'manual'})}, vectors)
            service.index = index
            row = service.search(image, 5, None, 'object', box)['results'][0]
            self.assertIsNotNone(row['tip_score'])
            self.assertLessEqual(abs(row['tip_score']), 1.0)
            # Full-image query against an object reference: global fallback, no tip.
            fallback = service.search(image, 5, None, 'original')['results'][0]
            self.assertIsNone(fallback['tip_score'])
            self.assertIsNone(fallback['object_score'])
        finally:
            index.close()


if __name__ == '__main__':
    unittest.main()
