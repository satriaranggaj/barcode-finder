"""Retention: old generations pruned, CURRENT never touched, failures never fatal.

Covers: CURRENT never deleted, newest-N kept, grace kept, old removed with
accounting, invalid dirs untouched, symlinks safe, failed cleanup never
fails publication, stale/fresh build-* handling, missing-CURRENT safety,
and publish-correctness after the change (incremental + rebuild).
"""
import os
import shutil
import tempfile
import time
import unittest
from dataclasses import replace
from pathlib import Path

from app.scripts.build_index import build
from app.scripts.retention import cleanup_index_root, read_current
from app.search.faiss_index import current_generation
from app.search.service import RetrievalService
from app.config import Settings
import test_retrieval as fixtures

MockEncoder = fixtures.MockEncoder
photo = fixtures.photo


def gen_name(tag):
    return f'generation-{(tag * 32)[:32]}'


class RetentionTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.root = Path(self.temp.name) / 'indexes'
        self.root.mkdir()

    def tearDown(self):
        self.temp.cleanup()

    def make_gen(self, tag, size=1024, age=0):
        name = gen_name(tag)
        directory = self.root / name
        directory.mkdir()
        (directory / 'payload.faiss').write_bytes(b'x' * size)
        old = time.time() - age
        os.utime(directory, (old, old))
        return name

    def publish(self, name):
        (self.root / 'CURRENT').write_text(name)

    def test_current_never_deleted_even_when_oldest(self):
        old = self.make_gen('a', age=100000)
        mid = self.make_gen('b', age=50000)
        new = self.make_gen('c', age=1000)
        self.publish(old)
        summary = cleanup_index_root(self.root, keep=1, grace_seconds=60, build_stale_hours=0)
        self.assertIn(old, summary['kept'])
        self.assertTrue((self.root / old).exists())
        self.assertIn(new, summary['kept'])
        self.assertNotIn(mid, summary['kept'])
        self.assertIn(mid, summary['removed'])
        self.assertFalse((self.root / mid).exists())

    def test_newest_n_and_grace_preserved_old_removed_with_accounting(self):
        g1 = self.make_gen('1', size=1024 * 100, age=100000)
        g2 = self.make_gen('2', size=1024 * 100, age=90000)
        g3 = self.make_gen('3', size=1024 * 100, age=80000)
        g4 = self.make_gen('4', size=1024 * 100, age=10)
        self.publish(g4)
        summary = cleanup_index_root(self.root, keep=2, grace_seconds=3600, build_stale_hours=0)
        # keep=2 newest (g4, g3) + grace (g4 young anyway); g1, g2 removed.
        self.assertEqual(sorted(summary['kept']), sorted([g4, g3]))
        self.assertEqual(sorted(summary['removed']), sorted([g1, g2]))
        self.assertGreaterEqual(summary['freed_mb'], 0.1)
        self.assertEqual((self.root / 'CURRENT').read_text(), g4)

    def test_invalid_and_unexpected_entries_untouched(self):
        (self.root / 'generation-short').mkdir()
        (self.root / 'generation-UPPERCASE12345678901234567890').mkdir()
        (self.root / 'random-dir').mkdir()
        (self.root / ('generation-' + 'f' * 32)).write_text('not a directory')
        current = self.make_gen('c', age=0)
        self.publish(current)
        summary = cleanup_index_root(self.root, keep=1, grace_seconds=0, build_stale_hours=0)
        self.assertTrue((self.root / 'generation-short').is_dir())
        self.assertTrue((self.root / 'random-dir').is_dir())
        self.assertTrue((self.root / ('generation-' + 'f' * 32)).is_file())
        self.assertEqual(summary['removed'], [])

    def test_symlinks_never_followed_or_deleted(self):
        outside = Path(self.temp.name) / 'outside'
        outside.mkdir()
        secret = outside / 'secret.faiss'
        secret.write_bytes(b'secret')
        link = self.root / gen_name('d')
        try:
            link.symlink_to(outside, target_is_directory=True)
        except (OSError, NotImplementedError):
            self.skipTest('symlinks unavailable')
        current = self.make_gen('c', age=0)
        self.publish(current)
        summary = cleanup_index_root(self.root, keep=1, grace_seconds=0, build_stale_hours=0)
        self.assertTrue(link.is_symlink())
        self.assertTrue(secret.exists())
        self.assertNotIn(link.name, summary['removed'])

    def test_missing_and_corrupt_current_removes_nothing(self):
        old = self.make_gen('e', age=100000)
        summary = cleanup_index_root(self.root, keep=1, grace_seconds=0, build_stale_hours=0)
        self.assertIsNone(summary['current'])
        self.assertTrue((self.root / old).exists())
        self.assertEqual(summary['removed'], [])
        (self.root / 'CURRENT').write_text('garbage!!!')
        self.assertIsNone(read_current(self.root))
        summary = cleanup_index_root(self.root, keep=1, grace_seconds=0, build_stale_hours=0)
        self.assertTrue((self.root / old).exists())
        self.assertEqual(summary['removed'], [])

    def test_failed_deletion_never_fails_cleanup(self):
        old = self.make_gen('f', age=100000)
        older = self.make_gen('0', age=200000)
        current = self.make_gen('c', age=0)
        self.publish(current)
        real_rmtree = shutil.rmtree

        def flaky(path, *args, **kwargs):
            if Path(path).name == old:
                raise PermissionError('denied')
            return real_rmtree(path, *args, **kwargs)

        import app.scripts.retention as retention_module
        original = retention_module.shutil.rmtree
        retention_module.shutil.rmtree = flaky
        try:
            summary = cleanup_index_root(self.root, keep=1, grace_seconds=0, build_stale_hours=0)
        finally:
            retention_module.shutil.rmtree = original
        self.assertTrue((self.root / old).exists())
        self.assertFalse((self.root / older).exists())
        self.assertIn(older, summary['removed'])
        self.assertNotIn(old, summary['removed'])

    def test_stale_build_dirs_removed_fresh_kept(self):
        stale = self.root / 'build-abcdef12'
        stale.mkdir()
        (stale / 'x').write_bytes(b'x')
        old = time.time() - 100000
        os.utime(stale, (old, old))
        fresh = self.root / 'build-12345678'
        fresh.mkdir()
        current = self.make_gen('c', age=0)
        self.publish(current)
        summary = cleanup_index_root(self.root, keep=3, grace_seconds=3600, build_stale_hours=24)
        self.assertEqual(summary['stale_builds_removed'], 1)
        self.assertFalse(stale.exists())
        self.assertTrue(fresh.exists())

    def test_missing_root_never_raises(self):
        summary = cleanup_index_root(self.root / 'nope', keep=1, grace_seconds=0, build_stale_hours=0)
        self.assertEqual(summary['removed'], [])
        self.assertEqual(summary['kept'], [])

    def test_incremental_publish_still_correct_with_cleanup_active(self):
        settings = Settings(preprocessing_mode='original', legacy_embed=False)
        encoders = {'siglip': MockEncoder(), 'dino': MockEncoder()}
        service = RetrievalService(replace(settings, index_generations_keep=1,
                                           index_generation_grace_seconds=0),
                                   encoders)
        dataset = Path(self.temp.name) / 'dataset'
        (dataset / 'SKU123').mkdir(parents=True)
        (dataset / 'SKU123' / 'a.png').write_bytes(photo((200, 20, 20)))
        first = build(dataset, self.root, service)
        gen1 = (self.root / 'CURRENT').read_text()
        (dataset / 'SKU123' / 'b.png').write_bytes(photo((20, 200, 20)))
        second = build(dataset, self.root, service)
        self.assertEqual((second['added'], second['skipped']), (1, 1))
        self.assertEqual(second['references'], 2)
        self.assertNotEqual((self.root / 'CURRENT').read_text(), gen1)
        # keep=1: superseded generation pruned, serving generation intact.
        self.assertFalse((self.root / gen1).exists())
        self.assertEqual(first['references'], 1)

    def test_rebuild_publish_still_correct_with_cleanup_active(self):
        settings = Settings(preprocessing_mode='original', legacy_embed=False)
        encoders = {'siglip': MockEncoder(), 'dino': MockEncoder()}
        service = RetrievalService(settings, encoders)
        dataset = Path(self.temp.name) / 'dataset'
        (dataset / 'SKU123').mkdir(parents=True)
        (dataset / 'SKU123' / 'a.png').write_bytes(photo((200, 20, 20)))
        (dataset / 'SKU123' / 'b.png').write_bytes(photo((20, 200, 20)))
        build(dataset, self.root, service)
        gen1 = (self.root / 'CURRENT').read_text()
        (dataset / 'SKU123' / 'b.png').unlink()
        result = build(dataset, self.root, service, rebuild=True)
        self.assertEqual(result['references'], 1)
        self.assertNotEqual((self.root / 'CURRENT').read_text(), gen1)
        from app.search.faiss_index import FaissIndexManager
        index = FaissIndexManager.load_index(current_generation(self.root))
        try:
            self.assertEqual(index.count, 1)
        finally:
            index.close()


if __name__ == '__main__':
    unittest.main()
