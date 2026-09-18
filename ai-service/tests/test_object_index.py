"""Object-centric FAISS index: dual object/global indexes, manifest v2, safe publishing."""
import json
import unittest
from dataclasses import replace
from unittest.mock import patch
from PIL import Image
from app.config import Settings
from app.preprocessing.selection import BoundingBox
from app.search.faiss_index import FaissIndexManager, current_generation
from app.search.service import RetrievalService
from app.scripts.build_index import build
import test_retrieval as fixtures
import test_object_centric as object_fixtures
MockEncoder = fixtures.MockEncoder


class ObjectIndexTests(unittest.TestCase):
    setUp = fixtures.RetrievalTests.setUp
    tearDown = fixtures.RetrievalTests.tearDown

    def add_object_ref(self, image, box, sku='001', image_id='one'):
        object_fixtures.ObjectCentricTests.add_object_ref(self, image, box, sku, image_id)

    def add_full_ref(self, image, sku='002', image_id='two'):
        object_fixtures.ObjectCentricTests.add_full_ref(self, image, sku, image_id)

    def test_object_and_global_index_membership(self):
        red = Image.new('RGB', (64, 64), (200, 10, 10))
        blue = Image.new('RGB', (64, 64), (10, 10, 200))
        self.add_object_ref(red, BoundingBox(0, 0, .5, 1), sku='A', image_id='obj')
        self.add_full_ref(blue, sku='B', image_id='full')
        model = 'siglip'
        red_object = self.service.embed(red, 'object', BoundingBox(0, 0, .5, 1))[0][model]['global']
        blue_context = self.service.embed(blue, 'original')[0][model]['context']
        object_ids = [i for i, _ in self.index.search(model, red_object, 10, None, 'object')]
        global_ids = [i for i, _ in self.index.search(model, blue_context, 10, None, 'global')]
        self.assertEqual(object_ids, [0])          # only the reference with a real object
        self.assertEqual(sorted(global_ids), [0, 1])  # every reference, full-image vectors
        default_ids = [i for i, _ in self.index.search(model, blue_context, 10)]  # default: global
        self.assertEqual(sorted(default_ids), [0, 1])

    def test_metadata_connects_vectors_to_product_sku_image_and_representation(self):
        image = Image.new('RGB', (64, 64), 'red')
        vectors, _ = self.service.embed(image, 'object', BoundingBox(0, 0, .5, 1))
        self.index.add_product_embedding({'sku': 'SKU-77', 'image_id': 'photos/77/front.png',
            'product_id': 'PROD-9', 'photo_hash': 'h-77', 'representation': 'object',
            'selection_source': 'manual', 'extra': '{}'}, vectors)
        metadata, reference_vectors = self.index.reference(0)
        self.assertEqual(metadata['product_id'], 'PROD-9')
        self.assertEqual(metadata['sku'], 'SKU-77')
        self.assertEqual(metadata['image_id'], 'photos/77/front.png')
        self.assertEqual(metadata['representation'], 'object')
        self.assertEqual(metadata['selection_source'], 'manual')
        self.assertEqual(self.index.skus([0]), {0: 'SKU-77'})
        self.assertEqual(set(reference_vectors['siglip']),
                         {'global', 'context', 'center', 'left', 'right', 'top', 'bottom'})

    def test_sparse_object_rows_map_to_reference_ids_before_and_after_reload(self):
        image = Image.new('RGB', (64, 64), 'red')
        vectors, _ = self.service.embed(image, 'original')
        for i, (representation, category) in enumerate([
            ('full', 'tools'), ('object', 'tools'), ('full', 'other'), ('object', 'other')
        ]):
            self.index.add_product_embedding({'sku': f'SKU{i}', 'image_id': str(i),
                'photo_hash': f'h{i}', 'representation': representation, 'category': category}, vectors)
        directory = self.root / 'mixed'
        self.index.save_index(directory)
        loaded = FaissIndexManager.load_index(directory)
        try:
            for index in (self.index, loaded):
                for model in ('siglip', 'dino'):
                    query = vectors[model]['global']
                    hits = index.search(model, query, 10, representation='object')
                    self.assertEqual(sorted(i for i, _ in hits), [1, 3])
                    for category, expected in [('tools', 1), ('other', 3)]:
                        hits = index.search(model, query, 10, category, 'object')
                        self.assertEqual([i for i, _ in hits], [expected])
                        self.assertEqual(index.reference(hits[0][0])[0]['sku'], f'SKU{expected}')
        finally:
            loaded.close()

    def test_manifest_v2_roundtrip_and_tamper_detection(self):
        self.add_full_ref(Image.new('RGB', (64, 64), 'red'), sku='A', image_id='one')
        self.add_object_ref(Image.new('RGB', (64, 64), 'red'), BoundingBox(0, 0, .5, 1), sku='B', image_id='two')
        directory = self.root / 'generation'
        self.index.save_index(directory)
        manifest = json.loads((directory / 'manifest.json').read_text(encoding='utf-8'))
        self.assertEqual(manifest['version'], 2)
        self.assertEqual(manifest['count'], 2)
        self.assertEqual(manifest['object_count'], 1)
        self.assertEqual(set(manifest['dimensions']), {'siglip', 'dino'})
        self.assertEqual(set(manifest['dimensions']['siglip']), {'object', 'global'})
        self.assertEqual(set(manifest['files']), {'metadata.sqlite', 'siglip.object.faiss',
            'siglip.global.faiss', 'dino.object.faiss', 'dino.global.faiss'})
        loaded = FaissIndexManager.load_index(directory)
        try:
            self.assertEqual(loaded.count, 2)
            self.assertEqual(loaded.object_count, 1)
            self.assertEqual(loaded.reference(1)[0]['representation'], 'object')
        finally:
            loaded.close()
        with (directory / 'dino.object.faiss').open('ab') as file:
            file.write(b'corrupt')
        with self.assertRaises(ValueError):
            FaissIndexManager.load_index(directory)

    def test_per_sku_shortlist_cap_prevents_sku_flooding(self):
        for i in range(6):
            self.add_full_ref(Image.new('RGB', (64, 64), (200, 10, 10)), sku='A', image_id=f'a{i}')
        self.add_full_ref(Image.new('RGB', (64, 64), (10, 10, 200)), sku='B', image_id='b0')
        query = Image.new('RGB', (64, 64), (200, 10, 10))
        capped = self.service.search(query, 50, None, 'original')
        self.assertEqual(capped['candidate_images'], 4)  # 3 for SKU A + 1 for SKU B
        self.assertIn('B', [row['sku'] for row in capped['results']])
        uncapped = RetrievalService(replace(self.settings, candidates_per_sku=50), self.encoders, self.index)
        self.assertEqual(uncapped.search(query, 50, None, 'original')['candidate_images'], 7)

    def test_corrupt_generation_is_never_published(self):
        dataset = self.root / 'dataset'
        (dataset / '001').mkdir(parents=True)
        (dataset / '001' / 'one.png').write_bytes(fixtures.photo())
        root = self.root / 'indexes'
        with patch('app.scripts.build_index.FaissIndexManager.load_index',
                   side_effect=ValueError('Snapshot checksum mismatch')):
            with self.assertRaises(ValueError):
                build(dataset, root, self.service)
        self.assertFalse((root / 'CURRENT').exists())
        self.assertEqual(list(root.iterdir()), [])  # corrupt generation removed, lock released

    def test_failed_publish_keeps_existing_index_usable(self):
        dataset = self.root / 'dataset'
        (dataset / '001').mkdir(parents=True)
        (dataset / '001' / 'one.png').write_bytes(fixtures.photo())
        root = self.root / 'indexes'
        self.assertEqual(build(dataset, root, self.service)['added'], 1)
        published = (root / 'CURRENT').read_text(encoding='utf-8')
        with patch('app.scripts.build_index.FaissIndexManager.load_index',
                   side_effect=ValueError('corrupt')):
            with self.assertRaises(ValueError):
                build(dataset, root, self.service, rebuild=True)
        self.assertEqual((root / 'CURRENT').read_text(encoding='utf-8'), published)
        self.assertEqual(len(list(root.glob('generation-*'))), 1)
        loaded = FaissIndexManager.load_index(current_generation(root))
        try:
            self.assertEqual(loaded.count, 1)
        finally:
            loaded.close()

    def test_old_index_version_rejected_clearly(self):
        self.add_full_ref(Image.new('RGB', (64, 64), 'red'))
        directory = self.root / 'generation'
        self.index.save_index(directory)
        manifest = json.loads((directory / 'manifest.json').read_text(encoding='utf-8'))
        manifest['version'] = 1
        (directory / 'manifest.json').write_text(json.dumps(manifest), encoding='utf-8')
        with self.assertRaisesRegex(ValueError, 'Unsupported index version'):
            FaissIndexManager.load_index(directory)

    def test_smoke_build_object_and_full_references(self):
        dataset = self.root / 'dataset'
        (dataset / 'OBJ').mkdir(parents=True)
        (dataset / 'FULL').mkdir(parents=True)
        obj_image = Image.new('RGB', (80, 80), (10, 10, 200))
        obj_image.paste((200, 10, 10), (0, 0, 40, 80))
        obj_image.save(dataset / 'OBJ' / 'front.png')
        (dataset / 'OBJ' / 'front.json').write_text(json.dumps(
            {'crop': {'x': 0, 'y': 0, 'width': .5, 'height': 1}, 'selection_source': 'manual'}), encoding='utf-8')
        Image.new('RGB', (80, 80), (20, 200, 20)).save(dataset / 'FULL' / 'full.png')
        (dataset / 'FULL' / 'full.json').write_text(json.dumps({'selection_source': 'full'}), encoding='utf-8')
        root = self.root / 'indexes'
        report = build(dataset, root, self.service)
        self.assertEqual(report['added'], 2)
        loaded = FaissIndexManager.load_index(current_generation(root))
        try:
            self.assertEqual(loaded.count, 2)
            self.assertEqual(loaded.object_count, 1)
            serving = RetrievalService(self.settings, self.encoders, loaded)
            result = serving.search(obj_image, 5, None, 'object', BoundingBox(0, 0, .5, 1))
            self.assertEqual(result['results'][0]['sku'], 'OBJ')
        finally:
            loaded.close()

    def test_object_index_settings_validated(self):
        self.assertEqual(Settings().object_index_weight, .8)
        self.assertEqual(Settings().candidates_per_sku, 3)
        with self.assertRaises(ValueError):
            Settings(object_index_weight=1.5)
        with self.assertRaises(ValueError):
            Settings(candidates_per_sku=0)
        with self.assertRaises(ValueError):
            Settings(candidates_per_sku=51)


if __name__ == '__main__':
    unittest.main()
