import asyncio
import importlib
import io
import sys
import types
import unittest
from unittest.mock import patch
from PIL import Image
from fastapi.testclient import TestClient


class ApiTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        # API tests isolate model/network; benchmark uses the actual CLIP model.
        stub = types.ModuleType('app.model')
        stub.MODEL_NAME = 'openai/clip-vit-base-patch32'
        stub.MODEL_REVISION = 'test-revision'
        stub.create_image_embedding = lambda image: [1.0] + [0.0]*511
        with patch.dict(sys.modules, {'app.model': stub}):
            cls.main = importlib.import_module('app.main')

    def setUp(self):
        self.main.gate = asyncio.Semaphore(1)
        self.client = TestClient(self.main.app)
        buffer = io.BytesIO()
        Image.new('RGB', (120, 80), 'red').save(buffer, format='PNG')
        self.contents = buffer.getvalue()

    def test_legacy_contract_and_object_features(self):
        old = self.client.post('/embed', files={'image': ('a.png', self.contents, 'image/png')})
        new = self.client.post('/features', files={'image': ('a.png', self.contents, 'image/png')})
        self.assertEqual(old.status_code, 200)
        self.assertEqual(new.status_code, 200)
        self.assertEqual(len(old.json()['embedding']), 512)
        self.assertEqual(new.json()['version'], 'object-v1')
        self.assertIn('descriptors', new.json())

    def test_corruption_and_inference_failure_do_not_expose_paths(self):
        response = self.client.post('/features', files={'image': ('a.png', b'broken', 'image/png')})
        self.assertEqual(response.status_code, 422)
        with patch.object(self.main, 'create_image_embedding', side_effect=RuntimeError('/private/model')):
            response = self.client.post('/embed', files={'image': ('a.png', self.contents, 'image/png')})
        self.assertEqual(response.status_code, 503)
        self.assertNotIn('/private', response.text)

    def test_busy_returns_bounded_error_and_preserves_gate(self):
        self.main.gate = asyncio.Semaphore(0)
        response = self.client.post('/embed', files={'image': ('a.png', self.contents, 'image/png')})
        self.assertEqual(response.status_code, 503)
        self.assertEqual(self.main.gate._value, 0)

    def test_fast_query_skips_all_descriptors(self):
        with patch.object(self.main,'describe') as describe:
            response = self.client.post('/features',data={'pipeline':'object-v2','details':'false'},files={'image':('a.png',self.contents,'image/png')})
        self.assertEqual(response.status_code,200)
        self.assertEqual(response.json()['version'],'object-v2')
        self.assertNotIn('descriptors',response.json())
        describe.assert_not_called()

    def test_lazy_descriptors_do_not_run_embedding(self):
        with patch.object(self.main,'create_image_embedding') as embed:
            response = self.client.post('/descriptors',data={'pipeline':'object-v2','signals':'color'},files={'image':('a.png',self.contents,'image/png')})
        self.assertEqual(response.status_code,200)
        self.assertIn('color',response.json()['descriptors'])
        self.assertNotIn('texture',response.json()['descriptors'])
        self.assertNotIn('local',response.json()['descriptors'])
        embed.assert_not_called()
