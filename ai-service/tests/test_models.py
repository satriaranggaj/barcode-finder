"""Opt-in real checkpoint smoke test. Set RUN_MODEL_SMOKE=1 (downloads models)."""
import os
import unittest
import tempfile
from pathlib import Path
from io import BytesIO
import numpy as np
from PIL import Image
from app.config import Settings
from app.search.service import RetrievalService, load_encoders
from app.scripts.build_index import build
from app.scripts.evaluate import evaluate
from app.search.faiss_index import FaissIndexManager, current_generation
from app.main import create_app
from fastapi.testclient import TestClient


@unittest.skipUnless(os.getenv('RUN_MODEL_SMOKE') == '1', 'requires real model downloads')
class ModelSmokeTest(unittest.TestCase):
    def test_real_encoder_batches_are_normalized(self):
        settings = Settings(device='cpu', legacy_embed=False, preprocessing_mode='original')
        encoders = load_encoders(settings)
        self.assertEqual(set(encoders), {'siglip', 'dino'})
        service = RetrievalService(settings, encoders)
        result, _ = service.embed(Image.new('RGB', (100, 180), (220, 30, 20)), 'original')
        self.assertEqual(set(result), {'siglip', 'dino'})
        for model, crops in result.items():
            self.assertEqual(len(crops), 6)
            self.assertEqual(crops['global'].size, 768 if model == 'siglip' else 384)
            for vector in crops.values():
                self.assertEqual(vector.dtype, np.float32)
                self.assertAlmostEqual(float(np.linalg.norm(vector)), 1, places=5)
        print('Verified model identities:', service.signature['models'])
        # Toy inputs test plumbing only, never product retrieval accuracy.
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            reference = root / 'dataset' / '001'
            reference.mkdir(parents=True)
            Image.new('RGB', (80, 100), (220, 30, 20)).save(reference / 'front.png')
            build(reference.parent, root / 'indexes', service)
            index = FaissIndexManager.load_index(current_generation(root / 'indexes'))
            try:
                serving = RetrievalService(settings, encoders, index)
                stream = BytesIO()
                Image.new('RGB', (90, 100), (200, 40, 25)).save(stream, format='PNG')
                with TestClient(create_app(settings, serving)) as client:
                    response = client.post('/search', files={'image': ('query.png', stream.getvalue())})
                    self.assertEqual(response.status_code, 200)
                    self.assertEqual(response.json()['results'][0]['sku'], '001')
                heldout = root / 'evaluation' / '001'
                heldout.mkdir(parents=True)
                (heldout / 'query.png').write_bytes(stream.getvalue())
                self.assertEqual(evaluate(heldout.parent, serving)['images_tested'], 1)
            finally:
                index.close()
