"""Real FAISS/SQLite and API tests, deterministic encoders; no network/model downloads."""
from dataclasses import replace
from io import BytesIO
from pathlib import Path
import tempfile
import unittest
from unittest.mock import patch
import numpy as np
from PIL import Image
from fastapi.testclient import TestClient
from app.config import Settings
from app.encoders.base import ImageEncoder, normalize
from app.main import create_app
from app.preprocessing.crop import multi_crop
from app.preprocessing.pipeline import Preprocessor, decode_image, InvalidImage, photo_hash
from app.search.faiss_index import FaissIndexManager, current_generation
from app.search.ranking import weighted_score, aggregate_skus, confidence
from app.search.ranking import model_score
from app.search.service import RetrievalService
from app.scripts.build_index import build
from app.scripts.evaluate import evaluate


class MockEncoder(ImageEncoder):
    identity = {'model': 'mock', 'revision': 'test-v1', 'pooling': 'mean'}

    def encode_images(self, images):
        return normalize(np.array([np.asarray(im).mean(axis=(0, 1)) + .01 for im in images]))


def photo(color=(200, 20, 20), size=(64, 64), fmt='PNG'):
    out = BytesIO()
    Image.new('RGB', size, color).save(out, format=fmt)
    return out.getvalue()


class RetrievalTests(unittest.TestCase):
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

    def add(self, sku, image_id, color=(200, 20, 20), category='tools'):
        image = Image.new('RGB', (64, 64), color)
        vectors, _ = self.service.embed(image, 'original')
        self.index.add_product_embedding({'sku': sku, 'image_id': image_id,
            'product_id': sku, 'category': category, 'photo_hash': photo_hash(image)}, vectors)

    def test_normalization_dtype_and_invalid_vectors(self):
        result = normalize(np.array([[3., 4.]], dtype=np.float64))
        self.assertEqual(result.dtype, np.float32)
        np.testing.assert_allclose(np.linalg.norm(result, axis=-1), 1)
        for vector in ([0, 0], [np.nan, 1], [np.inf, 1]):
            with self.assertRaises(ValueError):
                normalize(vector)

    def test_crops_are_large_and_global_is_untouched(self):
        image = Image.new('RGB', (100, 200))
        crops = multi_crop(image)
        self.assertEqual(set(crops), {'global', 'center', 'left', 'right', 'top', 'bottom'})
        self.assertIs(crops['global'], image)
        self.assertTrue(all(c.width >= 75 and c.height >= 150 for c in crops.values()))

    def test_preprocessing_failure_and_uncertainty_fall_back(self):
        image = Image.new('RGB', (100, 150), (200, 30, 40))
        for box, reason in ((None, 'foreground_uncertain'), ((-1, 0, 5, 5), 'foreground_invalid_box')):
            foreground = type('Detector', (), {'box': lambda _, im: box})()
            prepared = Preprocessor(foreground=foreground).prepare(image)
            self.assertEqual(prepared.mode, 'original')
            self.assertEqual(prepared.reason, reason)
            self.assertEqual(prepared.image.tobytes(), image.tobytes())
        with patch('app.preprocessing.foreground.GrabCutForeground.box', side_effect=RuntimeError()):
            self.assertEqual(Preprocessor().prepare(image).reason, 'foreground_failed')

    def test_decode_rejects_corrupt_unsupported_and_resource_excess(self):
        for data in (b'junk', photo()[:30], photo(fmt='GIF')):
            with self.assertRaises(InvalidImage):
                decode_image(data, 100000, 100000)
        with self.assertRaises(InvalidImage):
            decode_image(photo(), 100000, 10)
        self.assertEqual(decode_image(photo(), 100000, 100000).mode, 'RGB')

    def test_weights_validation_and_single_model_fallback(self):
        self.assertAlmostEqual(weighted_score({'siglip': .8, 'dino': .6}, self.settings.weights), .67)
        self.assertAlmostEqual(weighted_score({'dino': .6}, self.settings.weights), .6)
        with self.assertRaises(ValueError):
            Settings(siglip_weight=.5)
        with self.assertRaises(ValueError):
            Settings(local_weight=float('nan'))

    def test_local_reranking_changes_order_only_within_candidates(self):
        query = {'global': np.array([1., 0.]), 'center': np.array([0., 1.])}
        same_global = {'global': np.array([1., 0.]), 'center': np.array([1., 0.])}
        good_local = {'global': np.array([.9, np.sqrt(.19)]), 'center': np.array([0., 1.])}
        self.assertGreater(model_score(query, same_global, 0), model_score(query, good_local, 0))
        self.assertLess(model_score(query, same_global, .25), model_score(query, good_local, .25))

    def test_preprocessing_corrects_exif_and_valid_crop(self):
        image = Image.new('RGB', (100, 200), (200, 20, 20))
        exif = image.getexif()
        exif[274] = 6
        stream = BytesIO()
        image.save(stream, format='JPEG', exif=exif)
        decoded = decode_image(stream.getvalue(), 100000, 100000)
        self.assertEqual(decoded.size, (200, 100))
        foreground = type('Detector', (), {'box': lambda _, im: (10, 20, 80, 90)})()
        prepared = Preprocessor(foreground=foreground).prepare(decoded)
        self.assertEqual(prepared.mode, 'object')
        self.assertEqual(prepared.image.size, (70, 70))

    def test_encoder_runtime_failure_uses_remaining_model(self):
        self.add('001', 'one')
        with patch.object(self.encoders['siglip'], 'encode_images', side_effect=RuntimeError('offline')):
            with self.assertLogs('app.search.service', level='ERROR'):
                result = self.service.search(Image.new('RGB', (64, 64)), mode='original')
        self.assertEqual(result['query']['models'], ['dino'])
        self.assertIsNone(result['results'][0]['siglip_score'])

    def test_api_missing_index_and_upload_limit(self):
        service = RetrievalService(self.settings, self.encoders)
        with TestClient(create_app(self.settings, service)) as client:
            self.assertFalse(client.get('/health').json()['search_ready'])
            self.assertEqual(client.post('/search', files={'image': ('p.png', photo())}).status_code, 503)
            self.assertEqual(client.post('/embed', files={'image': ('p.png', photo())}).status_code, 503)
        small_limit = replace(self.settings, max_bytes=10)
        with TestClient(create_app(small_limit, self.service)) as client:
            self.assertEqual(client.post('/search', files={'image': ('p.png', photo())}).status_code, 413)

    def test_evaluation_duplicate_queries_rejected(self):
        self.add('001', 'one')
        folder = self.root / 'evaluation' / '001'
        folder.mkdir(parents=True)
        (folder / 'one.png').write_bytes(photo((190, 20, 20)))
        (folder / 'two.png').write_bytes(photo((190, 20, 20)))
        with self.assertRaisesRegex(ValueError, 'leakage'):
            evaluate(folder.parent, self.service)

    def test_candidate_reranking_is_bounded(self):
        # Return an intentionally oversized candidate stream to check reranking cap.
        self.add('001', 'one')
        with patch.object(self.index, 'search', return_value=[(i, .9) for i in range(150)]), \
             patch.object(self.index, 'skus', return_value={i: f'sku-{i}' for i in range(150)}):
            original = self.index.reference
            with patch.object(self.index, 'references',
                              side_effect=lambda ids: {i: original(0) for i in ids}) as read:
                result = self.service.search(Image.new('RGB', (64, 64)), mode='original')
        self.assertEqual(result['candidate_images'], 50)
        self.assertEqual(read.call_count, 1)
        self.assertEqual(len(read.call_args[0][0]), 50)

    def test_sku_aggregation_max_and_distinct_reference_count(self):
        result = aggregate_skus([{'sku': 'A', 'image_id': '1', 'score': .9},
                                {'sku': 'A', 'image_id': '2', 'score': .7},
                                {'sku': 'B', 'image_id': '3', 'score': .8}])
        self.assertEqual([x['sku'] for x in result], ['A', 'B'])
        self.assertEqual(result[0]['score'], .9)
        self.assertEqual(result[0]['matched_images'], 2)

    def test_faiss_save_load_and_real_category_selection(self):
        self.add('001', '1')
        self.add('002', '2', (20, 200, 20), 'flowers')
        query = self.encoders['siglip'].encode_image(Image.new('RGB', (64, 64), (200, 20, 20)))
        self.assertEqual(self.index.search('siglip', query, 5)[0][0], 0)
        self.assertEqual([i for i, _ in self.index.search('siglip', query, 5, 'flowers')], [1])
        self.assertEqual(self.index.search('siglip', query, 5, 'missing'), [])
        directory = self.root / 'generation'
        self.index.save_index(directory)
        loaded = FaissIndexManager.load_index(directory)
        try:
            self.assertEqual(loaded.search('siglip', query, 5)[0][0], 0)
            self.assertEqual(loaded.reference(1)[0]['sku'], '002')
            with self.assertRaises(ValueError):
                loaded.add_product_embedding({}, {})
        finally:
            loaded.close()
        with (directory / 'siglip.global.faiss').open('ab') as file:
            file.write(b'corrupt')
        with self.assertRaises(ValueError):
            FaissIndexManager.load_index(directory)

    def test_two_stage_grouping_model_fallback_and_confidence(self):
        self.add('001', 'front')
        self.add('001', 'side', (190, 30, 30))
        self.add('002', 'other', (20, 200, 20))
        service = RetrievalService(self.settings, {'dino': MockEncoder()}, self.index)
        result = service.search(Image.new('RGB', (64, 64), (200, 20, 20)), 1, mode='original')
        self.assertEqual(result['results'][0]['sku'], '001')
        self.assertEqual(result['results'][0]['matched_images'], 2)
        self.assertIsNone(result['results'][0]['siglip_score'])
        self.assertIsNotNone(result['score_gap'])  # Compute margin before top_k truncation.
        self.assertNotIn('global', result)
        self.assertEqual(confidence([{'score': 1}], .85, .08, .7)[0], 'medium')

    def test_pipeline_revision_mismatch_rejected(self):
        wrong = MockEncoder()
        wrong.identity = {**wrong.identity, 'revision': 'other'}
        with self.assertRaises(ValueError):
            RetrievalService(self.settings, {'siglip': wrong}, self.index)
        with self.assertRaises(ValueError):
            RetrievalService(replace(self.settings, max_side=512), self.encoders, self.index)

    def test_empty_index_returns_zero_and_missing_index_is_service_error(self):
        result = self.service.search(Image.new('RGB', (64, 64)), mode='original')
        self.assertEqual(result['results'], [])
        self.service.index = None
        with self.assertRaises(RuntimeError):
            self.service.search(Image.new('RGB', (64, 64)))

    def test_api_validates_inputs_and_keeps_legacy_contract(self):
        self.add('001', '1')
        with TestClient(create_app(self.settings, self.service, MockEncoder())) as client:
            self.assertTrue(client.get('/health').json()['search_ready'])
            response = client.post('/search', files={'image': ('p.png', photo(), 'image/png')})
            self.assertEqual(response.status_code, 200)
            self.assertEqual(response.json()['results'][0]['sku'], '001')
            for fields in ({'top_k': '0'}, {'top_k': '51'}, {'preprocessing_mode': 'bad'}):
                response = client.post('/search', data=fields, files={'image': ('p.png', photo())})
                self.assertEqual(response.status_code, 422)
            self.assertEqual(client.post('/search', files={'image': ('p.png', b'bad')}).status_code, 422)
            legacy = client.post('/embed', files={'image': ('p.png', photo())}).json()
            self.assertIn('embedding', legacy)
            debug = client.post('/embed', data={'representation': 'multi'}, files={'image': ('p.png', photo())}).json()
            self.assertEqual(set(debug['global']), {'siglip', 'dino'})
            self.assertEqual(len(debug['local']), 5)

    def test_search_selection_mode_contract(self):
        self.add('001', '1')
        with TestClient(create_app(self.settings, self.service, MockEncoder())) as client:
            manual = client.post('/search', data={'selection_mode': 'manual', 'crop_x': '0', 'crop_y': '0',
                                                  'crop_width': '.5', 'crop_height': '.5', 'preprocessing_mode': 'original'},
                                 files={'image': ('p.png', photo(), 'image/png')})
            self.assertEqual(manual.status_code, 200)
            body = manual.json()
            self.assertEqual(body['query']['selection_mode'], 'manual')
            self.assertEqual(body['query']['selection_used'], {'x': 0.0, 'y': 0.0, 'width': .5, 'height': .5})
            self.assertEqual(body['results'][0]['sku'], '001')
            full = client.post('/search', data={'selection_mode': 'full'}, files={'image': ('p.png', photo(), 'image/png')})
            self.assertEqual(full.status_code, 200)
            self.assertEqual(full.json()['query']['selection_mode'], 'full')
            self.assertIsNone(full.json()['query']['selection_used'])
            self.assertEqual(full.json()['results'][0]['sku'], '001')
            for fields in ({'selection_mode': 'manual'},
                           {'selection_mode': 'full', 'crop_x': '0', 'crop_y': '0', 'crop_width': '.5', 'crop_height': '.5'},
                           {'selection_mode': 'auto', 'crop_x': '0'},
                           {'crop_x': '0', 'crop_y': '0', 'crop_width': '0', 'crop_height': '.5'},
                           {'crop_x': '.9', 'crop_y': '0', 'crop_width': '.2', 'crop_height': '.5'},
                           {'crop_x': '0', 'crop_y': '0', 'crop_width': '.005', 'crop_height': '.5'}):
                response = client.post('/search', data=fields, files={'image': ('p.png', photo())})
                self.assertEqual(response.status_code, 422, fields)
            multi = client.post('/embed', data={'representation': 'multi', 'selection_mode': 'manual',
                                                'crop_x': '0', 'crop_y': '0', 'crop_width': '.5', 'crop_height': '.5'},
                                files={'image': ('p.png', photo())})
            self.assertEqual(multi.status_code, 200)
            self.assertEqual(multi.json()['query']['selection_mode'], 'manual')
            legacy = client.post('/embed', data={'selection_mode': 'manual',
                                                 'crop_x': '0', 'crop_y': '0', 'crop_width': '.5', 'crop_height': '.5'},
                                 files={'image': ('p.png', photo())})
            self.assertEqual(legacy.status_code, 200)
            self.assertIn('embedding', legacy.json())

    def test_health_reports_relevance_gating(self):
        from types import SimpleNamespace
        with TestClient(create_app(self.settings, self.service, MockEncoder())) as client:
            body = client.get('/health').json()
            self.assertFalse(body['relevance_gated'])
            self.assertIsNone(body['relevance_threshold'])
        gated = SimpleNamespace(encoders={}, index=None, policy={'threshold': 0.706})
        with TestClient(create_app(self.settings, gated, MockEncoder())) as client:
            body = client.get('/health').json()
            self.assertTrue(body['relevance_gated'])
            self.assertEqual(body['relevance_threshold'], 0.706)

    def test_incremental_build_and_failed_build_preserve_published_generation(self):
        dataset = self.root / 'dataset'
        (dataset / '001').mkdir(parents=True)
        (dataset / '001' / 'one.png').write_bytes(photo())
        root = self.root / 'indexes'
        self.assertEqual(build(dataset, root, self.service)['added'], 1)
        self.assertEqual(build(dataset, root, self.service)['skipped'], 1)
        pointer = (root / 'CURRENT').read_text()
        (dataset / '001' / 'one.png').write_bytes(photo((20, 200, 20)))
        with self.assertRaises(ValueError):
            build(dataset, root, self.service)
        self.assertEqual((root / 'CURRENT').read_text(), pointer)
        self.assertFalse((root / 'build.lock').exists())
        self.assertEqual(build(dataset, root, self.service, rebuild=True)['added'], 1)
        loaded = FaissIndexManager.load_index(current_generation(root))
        loaded.close()

    def test_held_out_evaluation_metrics_and_reference_leakage(self):
        self.add('001', 'one')
        dataset = self.root / 'evaluation'
        (dataset / '001').mkdir(parents=True)
        path = dataset / '001' / 'query.png'
        path.write_bytes(photo((195, 25, 25)))
        report = evaluate(dataset, self.service)
        self.assertEqual(report['images_tested'], 1)
        self.assertEqual(report['mrr'], 1)
        path.write_bytes(photo())
        with self.assertRaises(ValueError):
            evaluate(dataset, self.service)


if __name__ == '__main__':
    unittest.main()
