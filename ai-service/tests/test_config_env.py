"""Every setting is env-configurable, documented, validated and signed."""
import os
import unittest
from dataclasses import fields
from pathlib import Path
from unittest.mock import patch

from app.config import Settings

ENV_EXAMPLE = Path(__file__).resolve().parents[1] / '.env.example'
ALIASES = {'index_path': 'FAISS_INDEX_PATH', 'legacy_embed': 'ENABLE_LEGACY_EMBED'}


class ConfigEnvironmentTests(unittest.TestCase):
    def documented_names(self):
        names = set()
        for line in ENV_EXAMPLE.read_text().splitlines():
            line = line.strip()
            if line and not line.startswith('#') and '=' in line:
                names.add(line.split('=', 1)[0].strip())
        return names

    def test_every_field_is_env_configurable_and_documented(self):
        documented = self.documented_names()
        for field in fields(Settings):
            env_name = ALIASES.get(field.name, field.name.upper())
            self.assertIn(env_name, documented, f'{field.name} missing from .env.example')

    def test_from_env_round_trip_and_rejection(self):
        env = {'CANDIDATES': '100', 'OCR_ENABLED': 'true', 'PATCH_AGGREGATION': 'median',
               'SECONDARY_WEIGHT': '0.2', 'SELECTION_PADDING': '0.1', 'RELEVANCE_POLICY': '',
               'PROCESSING_MEMORY_MB': '512', 'MASTER_QUALITY': '90'}
        with patch.dict(os.environ, env, clear=False):
            settings = Settings.from_env()
        self.assertEqual(settings.candidates, 100)
        self.assertTrue(settings.ocr_enabled)
        self.assertEqual(settings.patch_aggregation, 'median')
        self.assertAlmostEqual(settings.secondary_weight, 0.2)
        for bad in ({'SECONDARY_WEIGHT': '0.9'}, {'PATCH_AGGREGATION': 'bogus'},
                    {'OCR_ENABLED': 'maybe'}, {'SELECTION_PADDING': '0.9'},
                    {'MASTER_QUALITY': '50'}, {'DEVICE': 'tpu'}):
            with self.assertRaises(ValueError, msg=str(bad)):
                with patch.dict(os.environ, bad, clear=False):
                    Settings.from_env()

    def test_signature_records_result_affecting_image_config(self):
        from app.search.service import RetrievalService
        import test_retrieval as fixtures
        service = RetrievalService(Settings(), {'siglip': fixtures.MockEncoder(),
                                                'dino': fixtures.MockEncoder()})
        image = service.evaluation_signature['image']
        self.assertEqual(image, {'master_quality': 88, 'catalog_quality': 83,
                                 'thumbnail_quality': 78, 'catalog_side': 1600,
                                 'thumbnail_side': 400})


    def test_dotenv_file_is_loaded_and_os_env_wins(self):
        try:
            import dotenv  # noqa: F401
        except ImportError:
            self.skipTest('python-dotenv not installed')
        import tempfile
        # load_dotenv writes into os.environ; snapshot it so file-backed keys
        # never leak into other tests.
        snapshot = dict(os.environ)
        try:
            # Importing app.main at collection time already loads the real
            # .env into os.environ; drop our keys so this test is hermetic.
            for key in ('CANDIDATES', 'RELEVANCE_POLICY'):
                os.environ.pop(key, None)
            with tempfile.TemporaryDirectory() as tmp:
                env_file = Path(tmp) / '.env'
                env_file.write_text('CANDIDATES=100\nRELEVANCE_POLICY=/tmp/policy.json\n', encoding='utf-8')
                settings = Settings.from_env(env_file=env_file)
                self.assertEqual(settings.candidates, 100)
                self.assertEqual(settings.relevance_policy, '/tmp/policy.json')
                with patch.dict(os.environ, {'CANDIDATES': '60'}):
                    self.assertEqual(Settings.from_env(env_file=env_file).candidates, 60)
                # Missing file behaves like before: pure OS env, never an error.
                with patch.dict(os.environ, {}, clear=True):
                    defaults = Settings.from_env(env_file=Path(tmp) / 'nope.env')
                self.assertEqual(defaults, Settings())
        finally:
            os.environ.clear()
            os.environ.update(snapshot)


if __name__ == '__main__':
    unittest.main()
