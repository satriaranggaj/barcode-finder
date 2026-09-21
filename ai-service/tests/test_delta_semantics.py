"""Delta-vs-snapshot semantics: incremental appends never false-report deletion.

Covers: delta with 1 new ref on 100 (TEST 1), unchanged existing skipped
without re-encode (TEST 2), changed bytes refused (TEST 3), changed
representation metadata refused (TEST 4), null-vs-missing key parity
(TEST 4b), rebuild with deletion (TEST 5), rebuild with changed crop
(TEST 6). Serving CURRENT is untouched by every refused build.
"""
import json
import tempfile
import unittest
from pathlib import Path
from unittest.mock import patch

from app.config import Settings
from app.search.faiss_index import FaissIndexManager, current_generation
from app.search.service import RetrievalService
from app.scripts.build_index import build, canonical_input
import test_retrieval as fixtures

MockEncoder = fixtures.MockEncoder
photo = fixtures.photo


def sidecar(path: Path, **fields):
    base = {'crop': {'x': .1, 'y': .1, 'width': .8, 'height': .8},
            'description': 'Obeng plus PH2',
            'selection_source': 'manual',
            'selection_verified': True,
            'source': 'catalog',
            'capture_group': 'reference-1',
            'product_id': '7'}
    base.update(fields)
    path.with_suffix('.json').write_text(json.dumps(base))


class DeltaSemanticsTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.root = Path(self.temp.name)
        self.settings = Settings(preprocessing_mode='original', legacy_embed=False)
        self.encoders = {'siglip': MockEncoder(), 'dino': MockEncoder()}
        self.service = RetrievalService(self.settings, self.encoders)
        self.dataset = self.root / 'dataset'
        self.index_root = self.root / 'indexes'

    def tearDown(self):
        self.temp.cleanup()

    def write_ref(self, sku, name, color, **sidecar_fields):
        folder = self.dataset / sku
        folder.mkdir(parents=True, exist_ok=True)
        target = folder / name
        target.write_bytes(photo(color))
        if sidecar_fields is not None:
            sidecar(target, **sidecar_fields)
        return target

    def current_count(self):
        index = FaissIndexManager.load_index(current_generation(self.index_root))
        try:
            return index.count
        finally:
            index.close()

    def test_delta_one_new_ref_on_hundred(self):
        folder = self.dataset / 'SKU123'
        folder.mkdir(parents=True, exist_ok=True)
        for i in range(100):
            (folder / f'photo-{i:03d}.png').write_bytes(photo((200, 20, 20)))
        first = build(self.dataset, self.index_root, self.service)
        self.assertEqual(first['references'], 100)
        old_generation = (self.index_root / 'CURRENT').read_text()
        # Delta carries ONLY the new reference, like appendPending workspaces.
        delta = self.root / 'delta'
        (delta / 'SKU123').mkdir(parents=True)
        (delta / 'SKU123' / 'photo-new.png').write_bytes(photo((20, 200, 20)))
        result = build(delta, self.index_root, self.service)
        self.assertEqual((result['added'], result['skipped']), (1, 0))
        self.assertEqual(result['references'], 101)
        self.assertEqual(self.current_count(), 101)
        self.assertNotEqual((self.index_root / 'CURRENT').read_text(), old_generation)
        self.assertTrue((self.index_root / old_generation).exists())

    def test_delta_existing_unchanged_is_skipped_without_reencode(self):
        self.write_ref('SKU123', 'a.png', (200, 20, 20))
        build(self.dataset, self.index_root, self.service)
        old_generation = (self.index_root / 'CURRENT').read_text()
        delta = self.root / 'delta'
        (delta / 'SKU123').mkdir(parents=True)
        delta_target = delta / 'SKU123' / 'a.png'
        delta_target.write_bytes(photo((200, 20, 20)))
        # A real appendPending delta always carries the same sidecar.
        sidecar(delta_target)
        with patch.object(self.service, 'embed', side_effect=AssertionError('unchanged ref re-encoded')):
            result = build(delta, self.index_root, self.service)
        self.assertEqual((result['added'], result['skipped']), (0, 1))
        self.assertEqual(result['references'], 1)
        self.assertEqual(self.current_count(), 1)
        self.assertNotEqual((self.index_root / 'CURRENT').read_text(), old_generation)

    def test_delta_changed_bytes_requires_rebuild_and_keeps_serving(self):
        self.write_ref('SKU123', 'a.png', (200, 20, 20))
        build(self.dataset, self.index_root, self.service)
        old_generation = (self.index_root / 'CURRENT').read_text()
        (self.dataset / 'SKU123' / 'a.png').write_bytes(photo((20, 200, 20)))
        with self.assertRaisesRegex(ValueError, 'rebuild'):
            build(self.dataset, self.index_root, self.service)
        self.assertEqual((self.index_root / 'CURRENT').read_text(), old_generation)
        self.assertEqual(self.current_count(), 1)

    def test_delta_changed_crop_requires_rebuild_and_keeps_serving(self):
        target = self.write_ref('SKU123', 'a.png', (200, 20, 20))
        build(self.dataset, self.index_root, self.service)
        old_generation = (self.index_root / 'CURRENT').read_text()
        sidecar(target, crop={'x': .2, 'y': .2, 'width': .5, 'height': .5})
        with self.assertRaisesRegex(ValueError, 'rebuild'):
            build(self.dataset, self.index_root, self.service)
        self.assertEqual((self.index_root / 'CURRENT').read_text(), old_generation)
        self.assertEqual(self.current_count(), 1)

    def test_null_vs_missing_optional_key_is_not_a_change(self):
        target = self.write_ref('SKU123', 'a.png', (200, 20, 20))
        raw = json.loads(target.with_suffix('.json').read_text(encoding='utf-8'))
        del raw['selection_source']
        target.with_suffix('.json').write_text(json.dumps(raw))
        build(self.dataset, self.index_root, self.service)
        # Same reference, now with the key present-but-null: semantically equal.
        raw['selection_source'] = None
        target.with_suffix('.json').write_text(json.dumps(raw))
        self.assertEqual(canonical_input({'selection_source': None}), canonical_input({}))
        result = build(self.dataset, self.index_root, self.service)
        self.assertEqual((result['added'], result['skipped']), (0, 1))

    def test_rebuild_with_deleted_reference(self):
        self.write_ref('SKU123', 'a.png', (200, 20, 20))
        self.write_ref('SKU123', 'b.png', (20, 200, 20))
        build(self.dataset, self.index_root, self.service)
        old_generation = (self.index_root / 'CURRENT').read_text()
        (self.dataset / 'SKU123' / 'b.png').unlink()
        (self.dataset / 'SKU123' / 'b.json').unlink()
        result = build(self.dataset, self.index_root, self.service, rebuild=True)
        self.assertEqual(result['references'], 1)
        self.assertEqual(self.current_count(), 1)
        self.assertNotEqual((self.index_root / 'CURRENT').read_text(), old_generation)
        self.assertTrue((self.index_root / old_generation).exists())

    def test_rebuild_with_changed_crop_applies_new_representation(self):
        target = self.write_ref('SKU123', 'a.png', (200, 20, 20))
        build(self.dataset, self.index_root, self.service)
        new_crop = {'x': .2, 'y': .2, 'width': .5, 'height': .5}
        sidecar(target, crop=new_crop)
        result = build(self.dataset, self.index_root, self.service, rebuild=True)
        self.assertEqual(result['references'], 1)
        index = FaissIndexManager.load_index(current_generation(self.index_root))
        try:
            metadata, _ = index.reference(0)
            self.assertEqual(json.loads(metadata['extra'])['crop'], new_crop)
            self.assertEqual(metadata['representation'], 'object')
        finally:
            index.close()


if __name__ == '__main__':
    unittest.main()
