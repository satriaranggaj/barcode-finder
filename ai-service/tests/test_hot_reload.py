"""Generation-aware hot reload: swap without restart, never break serving.

Covers: new generation picked up with no restart (I), invalid generation
keeps the old snapshot (H), in-use snapshots close only at zero users (J),
signature drift refuses incremental append (K).
"""
import sqlite3
import tempfile
import unittest
from dataclasses import replace
from pathlib import Path

from fastapi.testclient import TestClient

from app.config import Settings
from app.main import SnapshotPool, create_app
from app.scripts.build_index import build
from app.search.faiss_index import FaissIndexManager, current_generation
import test_retrieval as fixtures

MockEncoder = fixtures.MockEncoder
photo = fixtures.photo


def red():
    return photo((200, 20, 20))


def green():
    return photo((20, 200, 20))


class HotReloadTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.root = Path(self.temp.name)
        self.settings = Settings(preprocessing_mode='original', legacy_embed=False,
                                 index_path=self.root / 'indexes')
        self.encoders = {'siglip': MockEncoder(), 'dino': MockEncoder()}
        (self.root / 'dataset').mkdir(parents=True)

    def tearDown(self):
        service = getattr(self, 'service', None)
        index = getattr(service, 'index', None) if service is not None else None
        if index is not None:
            try:
                index.close()
            except Exception:
                pass
        self.temp.cleanup()

    def add_photo(self, sku, name, color):
        folder = self.root / 'dataset' / sku
        folder.mkdir(parents=True, exist_ok=True)
        (folder / name).write_bytes(photo(color))

    def build_service(self):
        from app.search.service import RetrievalService
        self.service = RetrievalService(self.settings, self.encoders)
        build(self.root / 'dataset', self.root / 'indexes', self.service)
        self.service.index = FaissIndexManager.load_index(current_generation(self.root / 'indexes'))
        return self.service

    def managed_client(self, service):
        app = create_app(self.settings, service, MockEncoder())
        app.state.managed = True
        return TestClient(app)

    def search_skus(self, client, color):
        response = client.post('/search', files={'image': ('q.png', photo(color), 'image/png')})
        self.assertEqual(response.status_code, 200)
        return [row['sku'] for row in response.json()['results']]

    def test_new_generation_is_picked_up_without_restart(self):
        self.add_photo('RED', 'a.png', (200, 20, 20))
        service = self.build_service()
        # Lifespan marks app-created snapshots owned; passed-in services stay
        # owned by their caller and are never closed by a swap.
        service._owned = True
        old_index, old_gen = service.index, current_generation(self.root / 'indexes').name
        with self.managed_client(service) as client:
            self.assertEqual(self.search_skus(client, (200, 20, 20))[0], 'RED')
            self.assertEqual(client.get('/health').json()['generation'], old_gen)
            # A new photo lands in a freshly published generation.
            self.add_photo('GREEN', 'b.png', (20, 200, 20))
            build(self.root / 'dataset', self.root / 'indexes', service)
            new_gen = current_generation(self.root / 'indexes').name
            self.assertNotEqual(new_gen, old_gen)
            self.assertEqual(self.search_skus(client, (20, 200, 20))[0], 'GREEN')
            self.assertEqual(client.get('/health').json()['generation'], new_gen)
        # Idle old snapshot is closed exactly once by the swap.
        with self.assertRaises(sqlite3.ProgrammingError):
            old_index.db.execute('SELECT 1')

    def test_invalid_generation_keeps_serving_old_snapshot(self):
        self.add_photo('RED', 'a.png', (200, 20, 20))
        service = self.build_service()
        with self.managed_client(service) as client:
            self.assertEqual(self.search_skus(client, (200, 20, 20))[0], 'RED')
            broken = self.root / 'indexes' / 'generation-broken'
            broken.mkdir()
            (self.root / 'indexes' / 'CURRENT').write_text('generation-broken')
            self.assertEqual(self.search_skus(client, (200, 20, 20))[0], 'RED')
            self.assertEqual(client.get('/health').json()['search_ready'], True)

    def test_signature_drift_refuses_incremental_append(self):
        self.add_photo('RED', 'a.png', (200, 20, 20))
        service = self.build_service()
        drifted = replace(self.settings, max_side=512)
        from app.search.service import RetrievalService
        with self.assertRaisesRegex(ValueError, 'rebuild'):
            build(self.root / 'dataset', self.root / 'indexes',
                  RetrievalService(drifted, self.encoders))

    def test_pool_never_closes_a_busy_snapshot(self):
        from types import SimpleNamespace
        pool = SnapshotPool()
        first = SimpleNamespace(_owned=True, _inflight=0, index=None)
        second = SimpleNamespace(_owned=True, _inflight=0, index=None)
        closed = []
        import app.main as main_module
        real_close = main_module._close_snapshot
        main_module._close_snapshot = lambda service: closed.append(service)
        try:
            pool.current = first
            held = pool.acquire()
            self.assertIs(held, first)
            pool.swap(second, 'generation-2')
            # Busy old snapshot stays open and usable...
            self.assertEqual(closed, [])
            self.assertEqual(pool.acquire(), second)
            pool.release(second)
            # ...until its last user leaves.
            pool.release(held)
            self.assertEqual(closed, [first])
            self.assertNotIn(first, pool.retired)
        finally:
            main_module._close_snapshot = real_close


if __name__ == '__main__':
    unittest.main()
