"""Verified confirmed_search references append safely; failures never touch production."""
import json
import tempfile
import unittest
from unittest.mock import patch
from dataclasses import replace
from pathlib import Path

from PIL import Image

from app.config import Settings
from app.search.faiss_index import FaissIndexManager, current_generation
from app.search.service import RetrievalService
from app.scripts.build_index import build
import test_retrieval as fixtures

MockEncoder = fixtures.MockEncoder
photo = fixtures.photo


def sidecar(path: Path, **fields):
    base = {'crop': {'x': .1, 'y': .1, 'width': .8, 'height': .8},
            'description': 'Obeng plus PH2',
            'selection_source': 'manual',
            'source': 'confirmed_search',
            'capture_group': 'verified-1',
            'product_id': '7'}
    base.update(fields)
    path.with_suffix('.json').write_text(json.dumps(base))


class IncrementalReferenceTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.root = Path(self.temp.name)
        self.settings = Settings(preprocessing_mode='object', legacy_embed=False)
        self.encoders = {'siglip': MockEncoder(), 'dino': MockEncoder()}
        self.service = RetrievalService(self.settings, self.encoders)
        self.dataset = self.root / 'dataset'
        self.index_root = self.root / 'indexes'
        (self.dataset / 'SKU123').mkdir(parents=True)
        (self.dataset / 'SKU123' / 'catalog.png').write_bytes(photo((200, 20, 20)))
        self.first = build(self.dataset, self.index_root, self.service)
        self.generation = (self.index_root / 'CURRENT').read_text()

    def tearDown(self):
        self.temp.cleanup()

    def serving(self):
        index = FaissIndexManager.load_index(current_generation(self.index_root))
        try:
            return RetrievalService(self.settings, self.encoders, index), index
        except Exception:
            index.close()
            raise

    def test_verified_reference_appends_and_is_searchable_with_metadata(self):
        (self.dataset / 'SKU123' / 'verified-9.png').write_bytes(photo((210, 25, 25)))
        sidecar(self.dataset / 'SKU123' / 'verified-9.png')
        result = build(self.dataset, self.index_root, self.service)
        self.assertEqual((result['added'], result['skipped']), (1, 1))
        self.assertNotEqual((self.index_root / 'CURRENT').read_text(), self.generation)
        service, index = self.serving()
        try:
            self.assertEqual(index.count, 2)
            metadata, _ = index.reference(1)
            self.assertEqual((metadata['sku'], metadata['image_id']), ('SKU123', 'SKU123/verified-9.png'))
            self.assertEqual(metadata['representation'], 'object')
            self.assertEqual(metadata['selection_source'], 'manual')
            extra = json.loads(metadata['extra'])
            self.assertEqual(extra['source'], 'confirmed_search')
            self.assertEqual(extra['description'], 'Obeng plus PH2')
            self.assertEqual(extra['capture_group'], 'verified-1')
            found = service.search(Image.new('RGB', (64, 64), (210, 25, 25)), mode='object')
            self.assertEqual(found['results'][0]['sku'], 'SKU123')
            self.assertEqual(found['results'][0]['image_id'], 'SKU123/verified-9.png')
        finally:
            index.close()

    def test_failed_append_keeps_published_generation_usable(self):
        (self.dataset / 'SKU123' / 'verified-9.png').write_bytes(photo((210, 25, 25)))
        sidecar(self.dataset / 'SKU123' / 'verified-9.png')
        (self.dataset / 'SKU123' / 'aaa-corrupt.png').write_bytes(b'not an image')
        with self.assertRaises(ValueError):
            build(self.dataset, self.index_root, self.service)
        self.assertEqual((self.index_root / 'CURRENT').read_text(), self.generation)
        self.assertFalse((self.index_root / 'build.lock').exists())
        service, index = self.serving()
        try:
            self.assertEqual(index.count, 1)
            found = service.search(Image.new('RGB', (64, 64), (200, 20, 20)), mode='object')
            self.assertEqual(found['results'][0]['sku'], 'SKU123')
        finally:
            index.close()

    def test_changed_duplicate_requires_rebuild(self):
        (self.dataset / 'SKU123' / 'catalog.png').write_bytes(photo((20, 200, 20)))
        with self.assertRaisesRegex(ValueError, 'rebuild'):
            build(self.dataset, self.index_root, self.service)
        self.assertEqual((self.index_root / 'CURRENT').read_text(), self.generation)

    def test_auto_selected_reference_is_skipped_on_unchanged_append(self):
        proposal = {'boxes': [{'x': .1, 'y': .1, 'width': .8, 'height': .8}], 'reason': 'fixture_auto'}
        with patch.object(self.service.pipeline, 'propose', return_value=proposal):
            build(self.dataset, self.index_root, self.service, rebuild=True)
        with patch.object(self.service, 'embed', side_effect=AssertionError('Unchanged reference re-encoded')):
            result = build(self.dataset, self.index_root, self.service)
        self.assertEqual((result['added'], result['skipped']), (0, 1))

    def test_incompatible_signature_refuses_incremental_append(self):
        (self.dataset / 'SKU123' / 'verified-9.png').write_bytes(photo((210, 25, 25)))
        sidecar(self.dataset / 'SKU123' / 'verified-9.png')
        changed = RetrievalService(replace(self.settings, max_side=512), self.encoders)
        with self.assertRaisesRegex(ValueError, 'rebuild'):
            build(self.dataset, self.index_root, changed)
        self.assertEqual((self.index_root / 'CURRENT').read_text(), self.generation)

    def test_appended_generation_reloads_with_intact_metadata(self):
        (self.dataset / 'SKU123' / 'verified-9.png').write_bytes(photo((210, 25, 25)))
        sidecar(self.dataset / 'SKU123' / 'verified-9.png')
        build(self.dataset, self.index_root, self.service)
        reloaded = FaissIndexManager.load_index(current_generation(self.index_root))
        try:
            self.assertEqual(reloaded.count, 2)
            metadata, reference = reloaded.reference(1)
            self.assertEqual(metadata['photo_hash'], reloaded.reference(1)[0]['photo_hash'])
            self.assertIn('global', reference['siglip'])
            self.assertIn('context', reference['dino'])
        finally:
            reloaded.close()


if __name__ == '__main__':
    unittest.main()
