"""Multiple references per SKU: best valid view wins, counts never inflate scores."""
import itertools
import tempfile
import unittest
from dataclasses import replace
from pathlib import Path

from PIL import Image

from app.config import Settings
from app.search.faiss_index import FaissIndexManager
from app.search.ranking import aggregate_skus
from app.search.service import RetrievalService
import test_retrieval as fixtures

MockEncoder = fixtures.MockEncoder


def rows(*triples):
    return [{'sku': sku, 'image_id': image_id, 'score': score}
            for sku, image_id, score in triples]


class MultiReferenceAggregationTests(unittest.TestCase):
    def test_same_sku_multiple_views_best_view_wins_with_evidence(self):
        result = aggregate_skus(rows(
            ('SKU123', 'catalog', .70),
            ('SKU123', 'front', .92),
            ('SKU123', 'back', .81),
            ('SKU123', 'side', .66),
            ('SKU123', 'detail', .78),
        ))
        self.assertEqual(len(result), 1)
        winning = result[0]
        self.assertEqual(winning['sku'], 'SKU123')
        self.assertEqual(winning['score'], .92)
        self.assertEqual(winning['image_id'], 'front')
        self.assertEqual(winning['matched_images'], 5)
        self.assertEqual(winning['matched_image_ids'],
                         ['front', 'back', 'detail', 'catalog', 'side'])

    def test_irrelevant_extra_references_do_not_raise_score(self):
        base = aggregate_skus(rows(('A', 'front', .88), ('B', 'only', .80)))
        with_extras = aggregate_skus(rows(
            ('A', 'front', .88), ('A', 'blurry-back', .20),
            ('A', 'wrong-angle', .35), ('B', 'only', .80)))
        self.assertEqual([r['sku'] for r in with_extras], ['A', 'B'])
        self.assertEqual(with_extras[0]['score'], base[0]['score'])
        self.assertEqual(with_extras[0]['image_id'], 'front')
        self.assertEqual(with_extras[0]['matched_images'], 3)

    def test_sku_with_more_refs_does_not_win_by_count(self):
        many = [('MANY', f'view-{i:02d}', .70 - i * .01) for i in range(10)]
        result = aggregate_skus(rows(*many, ('FEW', 'only', .85)))
        self.assertEqual(result[0]['sku'], 'FEW')
        self.assertEqual(result[0]['score'], .85)
        self.assertEqual(result[1]['sku'], 'MANY')
        self.assertEqual(result[1]['score'], .70)
        self.assertEqual(result[1]['matched_images'], 10)

    def test_aggregation_is_deterministic_regardless_of_input_order(self):
        images = rows(('A', 'a2', .9), ('B', 'b1', .9), ('A', 'a1', .9),
                      ('B', 'b2', .7), ('A', 'a3', .5))
        expected = aggregate_skus(images)
        for permutation in itertools.islice(itertools.permutations(images), 0, 40):
            actual = aggregate_skus(list(permutation))
            self.assertEqual(
                [(r['sku'], r['score'], r['image_id'], r['matched_images'],
                  r['matched_image_ids']) for r in actual],
                [(r['sku'], r['score'], r['image_id'], r['matched_images'],
                  r['matched_image_ids']) for r in expected])
        # Equal scores break ties by SKU, then by image_id for the winning view.
        self.assertEqual([r['sku'] for r in expected], ['A', 'B'])
        self.assertEqual(expected[0]['image_id'], 'a1')

    def test_final_ranking_is_sku_level_not_image_level(self):
        result = aggregate_skus(rows(
            ('A', 'a1', .9), ('B', 'b1', .85), ('A', 'a2', .8), ('B', 'b2', .75)))
        self.assertEqual([r['sku'] for r in result], ['A', 'B'])
        self.assertEqual(len(result), 2)


class MultiReferenceRetrievalTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.root = Path(self.temp.name)
        self.settings = Settings(preprocessing_mode='original', legacy_embed=False)
        self.encoders = {'siglip': MockEncoder(), 'dino': MockEncoder()}
        self.service = RetrievalService(self.settings, self.encoders)
        self.index = FaissIndexManager(self.root / 'db.sqlite', self.service.signature)
        self.service.index = self.index

    def tearDown(self):
        self.index.close()
        self.temp.cleanup()

    def add(self, sku, image_id, color):
        from app.preprocessing.pipeline import photo_hash
        image = Image.new('RGB', (64, 64), color)
        vectors, _ = self.service.embed(image, 'original')
        self.index.add_product_embedding(
            {'sku': sku, 'image_id': image_id, 'product_id': sku,
             'category': 'tools', 'photo_hash': photo_hash(image)}, vectors)

    def index_catalog(self):
        self.add('SKU123', 'catalog', (200, 20, 20))
        self.add('SKU123', 'front', (210, 25, 25))
        self.add('SKU123', 'back', (190, 30, 30))
        self.add('SKU123', 'side', (205, 22, 28))
        self.add('SKU123', 'detail', (195, 35, 35))
        self.add('OTHER', 'only', (20, 20, 200))

    def test_relevant_view_found_and_reported_as_evidence(self):
        self.index_catalog()
        result = self.service.search(Image.new('RGB', (64, 64), (210, 25, 25)),
                                     mode='original')
        winning = result['results'][0]
        self.assertEqual(winning['sku'], 'SKU123')
        self.assertEqual(winning['image_id'], 'front')
        self.assertGreaterEqual(winning['matched_images'], 2)
        self.assertIn('front', winning['matched_image_ids'])
        self.assertEqual(winning['matched_images'], len(winning['matched_image_ids']))

    def test_more_references_do_not_crowd_out_other_skus(self):
        self.index_catalog()
        capped = RetrievalService(replace(self.settings, candidates_per_sku=1),
                                  self.encoders, self.index)
        result = capped.search(Image.new('RGB', (64, 64), (210, 25, 25)),
                               mode='original')
        skus = [row['sku'] for row in result['results']]
        self.assertIn('SKU123', skus)
        self.assertIn('OTHER', skus)
        for row in result['results']:
            self.assertLessEqual(row['matched_images'], 1)

    def test_search_ranking_is_repeatable(self):
        self.index_catalog()
        query = Image.new('RGB', (64, 64), (205, 22, 28))
        first = self.service.search(query, mode='original')
        second = self.service.search(query, mode='original')
        self.assertEqual(
            [(r['sku'], r['score'], r['image_id']) for r in first['results']],
            [(r['sku'], r['score'], r['image_id']) for r in second['results']])


if __name__ == '__main__':
    unittest.main()
