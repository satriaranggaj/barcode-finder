"""POST /search contract: typed schema, no leaks, backward compatible."""
import unittest
from unittest.mock import patch
from dataclasses import replace
from io import BytesIO
import tempfile
from pathlib import Path

from PIL import Image
from fastapi.testclient import TestClient

from app.config import Settings
from app.main import create_app
from app.preprocessing.pipeline import photo_hash
from app.schemas import AmbiguityAlternative, SearchCandidate, SearchQueryInfo, SearchResponse
from app.search.faiss_index import FaissIndexManager
from app.search.service import RetrievalService, clip_similarity
import test_retrieval as fixtures

MockEncoder = fixtures.MockEncoder
photo = fixtures.photo


def walk(node, path='$'):
    if isinstance(node, dict):
        for key, value in node.items():
            yield f'{path}.{key}', value
            yield from walk(value, f'{path}.{key}')
    elif isinstance(node, list):
        for index, value in enumerate(node):
            yield from walk(value, f'{path}[{index}]')


def assert_no_leaks(test, body):
    for path, value in walk(body):
        key = path.split('.')[-1].lower()
        test.assertNotIn('embedding', key, path)
        test.assertNotIn('vector', key, path)
        if isinstance(value, list) and value and all(isinstance(v, (int, float)) for v in value):
            test.assertLessEqual(len(value), 16, f'numeric array at {path}')
        if isinstance(value, str) and ('..' in value or value.startswith('/') or ': Sid' in value):
            test.fail(f'suspicious path-like value at {path}')


class SearchContractTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.root = Path(self.temp.name)
        self.settings = Settings(preprocessing_mode='original', legacy_embed=False)
        encoders = {'siglip': MockEncoder(), 'dino': MockEncoder()}
        self.service = RetrievalService(self.settings, encoders)
        self.index = FaissIndexManager(self.root / 'db.sqlite', self.service.signature)
        self.service.index = self.index
        image = Image.new('RGB', (64, 64), (200, 20, 20))
        vectors, _ = self.service.embed(image, 'original')
        self.index.add_product_embedding({'sku': '001', 'image_id': 'front',
            'product_id': '7', 'category': 'tools', 'sub_category': 'drivers',
            'family': 'screwdrivers', 'photo_hash': photo_hash(image)}, vectors)
        self.client = TestClient(create_app(self.settings, self.service, MockEncoder()))
        self.client.__enter__()
        self.addCleanup(self.client.__exit__, None, None, None)

    def tearDown(self):
        self.index.close()
        self.temp.cleanup()

    def post_search(self, **form):
        return self.client.post('/search', data=form,
                                files={'image': ('p.png', photo(), 'image/png')})

    def test_full_response_matches_typed_schema(self):
        body = self.post_search().json()
        parsed = SearchResponse.model_validate(body)
        self.assertTrue(parsed.success)
        self.assertIsInstance(parsed.query, SearchQueryInfo)
        self.assertEqual(parsed.results[0].sku, '001')
        self.assertIsInstance(parsed.results[0], SearchCandidate)
        self.assertEqual(parsed.results[0].family, 'screwdrivers')
        self.assertGreaterEqual(parsed.stage_ms['decode_ms'], 0)
        self.assertIn('text_ms', parsed.stage_ms)
        assert_no_leaks(self, body)

    def test_auto_proposal_is_used_without_reselection(self):
        with patch.object(self.service.pipeline, 'propose', side_effect=AssertionError('Reselected displayed box')):
            response = self.post_search(selection_mode='auto', crop_x='.1', crop_y='.1',
                                        crop_width='.8', crop_height='.8')
        self.assertEqual(response.status_code, 200)
        self.assertEqual(response.json()['query']['selection_used'], {'x': .1, 'y': .1, 'width': .8, 'height': .8})

    def test_full_mode_overrides_object_preprocessing(self):
        with patch.object(self.service.pipeline, 'propose', side_effect=AssertionError('Full mode detected an object')):
            response = self.post_search(selection_mode='full', preprocessing_mode='object')
        self.assertEqual(response.status_code, 200)
        self.assertIsNone(response.json()['query']['selection_used'])
        self.assertEqual(response.json()['query']['requested_preprocessing'], 'original')

    def test_manual_crop_full_and_feedback_variants_validate(self):
        manual = self.post_search(crop_x='0', crop_y='0', crop_width='.5', crop_height='.5',
                                  preprocessing_mode='original').json()
        SearchResponse.model_validate(manual)
        self.assertIsNotNone(manual['query']['selection_used'])
        assert_no_leaks(self, manual)
        feedback = self.post_search(feedback_preview='true').json()
        parsed = SearchResponse.model_validate(feedback)
        self.assertIsNotNone(parsed.feedback)
        self.assertEqual(len(parsed.feedback.photo_hash), 64)
        assert_no_leaks(self, feedback)
        empty = self.post_search(top_k='1').json()
        SearchResponse.model_validate(empty)

    def test_response_model_drops_nothing_the_service_returns(self):
        raw = self.service.search(Image.new('RGB', (64, 64), (200, 20, 20)), mode='original')
        body = self.post_search().json()
        for key in raw:
            self.assertIn(key, body, f'service field {key} dropped by schema')
        for raw_row, sent_row in zip(raw['results'], body['results']):
            for key in raw_row:
                self.assertIn(key, sent_row, f'service field {key} dropped by schema')
            self.assertIn('rank', sent_row)

    def test_request_validation_rejects_bad_input(self):
        for fields in ({'top_k': '0'}, {'top_k': '51'}, {'preprocessing_mode': 'bad'},
                       {'crop_x': '0', 'crop_y': '0'}):
            self.assertEqual(self.post_search(**fields).status_code, 422, fields)

    def test_unknown_fields_stay_backward_compatible(self):
        response = self.post_search(future_flag='1')
        self.assertEqual(response.status_code, 200)
        SearchResponse.model_validate(response.json())

    def test_ambiguity_block_validates(self):
        body = self.post_search().json()
        for alternative in body['ambiguity_alternatives']:
            AmbiguityAlternative.model_validate(alternative)
        self.assertIsInstance(body['ambiguous'], bool)

    def test_similarity_clamp_keeps_schema_bounds(self):
        self.assertEqual(clip_similarity(1.00000008), 1.0)
        self.assertEqual(clip_similarity(-1.00000008), -1.0)
        self.assertEqual(clip_similarity(0.5), 0.5)

    def test_float32_similarity_overshoot_stays_in_schema(self):
        # float32 self-dots of near-identical images overshoot 1.0 by ~1e-7;
        # the typed response must still validate instead of 500ing.
        image = Image.new('RGB', (64, 64), (0, 62, 47))
        vectors, _ = self.service.embed(image, 'original')
        self.index.add_product_embedding({'sku': '002', 'image_id': 'teal',
            'product_id': '8', 'category': 'tools', 'photo_hash': photo_hash(image)}, vectors)
        stream = BytesIO()
        image.save(stream, format='PNG')
        response = self.client.post('/search', data={'top_k': '5'},
                                    files={'image': ('q.png', stream.getvalue(), 'image/png')})
        self.assertEqual(response.status_code, 200, response.text[:500])
        parsed = SearchResponse.model_validate(response.json())
        self.assertTrue(parsed.results)
        for row in parsed.results:
            for field in ('score', 'visual_score', 'object_score', 'global_score',
                          'siglip_score', 'dino_score', 'local_score', 'text_score', 'ocr_score'):
                value = getattr(row, field)
                if value is not None:
                    self.assertLessEqual(value, 1.0, field)
                    self.assertGreaterEqual(value, -1.0, field)


if __name__ == '__main__':
    unittest.main()
