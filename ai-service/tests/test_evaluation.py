"""Evaluation metrics, dataset versioning and leakage guards."""
import json
import tempfile
import unittest
from unittest.mock import patch
from pathlib import Path

from PIL import Image

from app.config import Settings
from app.search.faiss_index import FaissIndexManager
from app.search.service import RetrievalService
from app.scripts.evaluate import evaluate, format_summary
import test_retrieval as fixtures

MockEncoder = fixtures.MockEncoder
photo = fixtures.photo


class EvaluationPipelineTests(unittest.TestCase):
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

    def add(self, sku, image_id, color=(200, 20, 20), extra=None, family=None):
        from app.preprocessing.pipeline import photo_hash
        image = Image.new('RGB', (64, 64), color)
        vectors, _ = self.service.embed(image, 'original')
        metadata = {'sku': sku, 'image_id': image_id, 'product_id': sku,
                    'category': 'tools', 'family': family, 'photo_hash': photo_hash(image)}
        if extra is not None:
            metadata['extra'] = json.dumps(extra)
        self.index.add_product_embedding(metadata, vectors)

    def write_query(self, dataset, sku, name, color, sidecar=None):
        folder = dataset / sku
        folder.mkdir(parents=True, exist_ok=True)
        (folder / name).write_bytes(photo(color))
        if sidecar is not None:
            (folder / Path(name).with_suffix('.json').name).write_text(json.dumps(sidecar))

    def test_metrics_keys_ranges_and_signature_thresholds(self):
        self.add('A', 'a', (200, 20, 20))
        self.add('B', 'b', (20, 200, 20))
        dataset = self.root / 'evaluation'
        self.write_query(dataset, 'A', 'q1.png', (198, 22, 22),
                         {'capture_group': 'eval-1'})
        self.write_query(dataset, 'B', 'q2.png', (22, 198, 22),
                         {'capture_group': 'eval-2'})
        report = evaluate(dataset, self.service)
        for key in ('top_1_accuracy', 'top_3_accuracy', 'top_5_accuracy', 'mrr',
                    'median_latency_ms', 'p95_latency_ms'):
            self.assertIn(key, report)
            self.assertGreaterEqual(report[key], 0, key)
        self.assertLessEqual(report['top_1_accuracy'], 1.0)
        self.assertLessEqual(report['mrr'], 1.0)
        self.assertGreaterEqual(report['median_latency_ms'], 0.0)
        self.assertEqual(report['images_tested'], 2)
        signature = report['evaluation_signature']
        self.assertIn('thresholds', signature)
        self.assertEqual(set(signature['thresholds']),
                         {'high_score', 'high_gap', 'medium_score'})
        self.assertTrue(report['dataset_version'].startswith('eval-'))
        self.assertEqual(len(report['queries']), 2)

    def test_dataset_version_is_stable_and_content_sensitive(self):
        self.add('A', 'a', (200, 20, 20))
        dataset = self.root / 'evaluation'
        self.write_query(dataset, 'A', 'q1.png', (198, 22, 22))
        first = evaluate(dataset, self.service)['dataset_version']
        second = evaluate(dataset, self.service)['dataset_version']
        self.assertEqual(first, second)
        self.write_query(dataset, 'A', 'q2.png', (190, 30, 30))
        self.assertNotEqual(evaluate(dataset, self.service)['dataset_version'], first)

    def test_capture_group_overlap_with_reference_is_rejected(self):
        self.add('A', 'a', (200, 20, 20), extra={'capture_group': 'session-9'})
        dataset = self.root / 'evaluation'
        self.write_query(dataset, 'A', 'q1.png', (198, 22, 22),
                         {'capture_group': 'session-9'})
        with self.assertRaisesRegex(ValueError, 'Capture-group'):
            evaluate(dataset, self.service)

    def test_original_upload_is_not_heldout_after_reference_reencoding(self):
        from app.preprocessing.pipeline import photo_hash
        # The stored lossy master has different pixels; its original identity
        # is retained by the Laravel export sidecar.
        original = Image.new('RGB', (64, 64), (198, 22, 22))
        self.add('A', 'master', (200, 20, 20), extra={'source_photo_hash': photo_hash(original)})
        dataset = self.root / 'evaluation'
        self.write_query(dataset, 'A', 'original.png', (198, 22, 22))
        with self.assertRaisesRegex(ValueError, 'reference leakage'):
            evaluate(dataset, self.service)

    def test_dataset_version_changes_with_labels_and_crop(self):
        self.add('A', 'a')
        dataset = self.root / 'evaluation'
        versions = []
        for metadata in ({'relevant_skus': ['A']}, {'relevant_skus': []},
                         {'relevant_skus': [], 'crop': {'x': .1, 'y': .1, 'width': .8, 'height': .8}}):
            self.write_query(dataset, 'A', 'q.png', (198, 22, 22), metadata)
            versions.append(evaluate(dataset, self.service)['dataset_version'])
        self.assertEqual(len(set(versions)), 3)

    def test_positive_empty_result_reports_false_rejection_without_crashing(self):
        self.add('A', 'a')
        dataset = self.root / 'evaluation'
        self.write_query(dataset, 'A', 'q.png', (198, 22, 22))
        with patch.object(self.service, 'search', return_value={'results': [], 'latency_ms': 1}):
            report = evaluate(dataset, self.service)
        self.assertEqual(report['fn'], 1)
        self.assertEqual(report['positive_query_false_rejection'], 1)
        self.assertIsNone(report['errors'][0]['predicted_sku'])
        self.assertIsNone(report['errors'][0]['predicted']['score'])

    def test_mrr_includes_relevant_result_beyond_top_five(self):
        self.add('A', 'a')
        dataset = self.root / 'evaluation'
        self.write_query(dataset, 'A', 'q.png', (198, 22, 22))
        results = [{'sku': str(i), 'score': 1-i*.1} for i in range(5)] + [{'sku': 'A', 'score': .4}]
        with patch.object(self.service, 'search', return_value={'results': results, 'latency_ms': 1}):
            report = evaluate(dataset, self.service)
        self.assertAlmostEqual(report['mrr'], 1/6)
        self.assertEqual(report['mrr_cutoff'], 50)
        self.assertEqual(report['top_5_accuracy'], 0)

    def test_empty_dataset_is_rejected(self):
        self.add('A', 'a', (200, 20, 20))
        (self.root / 'evaluation' / 'A').mkdir(parents=True)
        with self.assertRaisesRegex(ValueError, 'no supported images'):
            evaluate(self.root / 'evaluation', self.service)

    def test_family_breakdown_uses_index_metadata_with_unknown_bucket(self):
        self.add('A', 'a', (200, 20, 20), family='screwdrivers')
        self.add('B', 'b', (20, 200, 20))
        dataset = self.root / 'evaluation'
        self.write_query(dataset, 'A', 'q1.png', (198, 22, 22), {'tags': ['handheld']})
        self.write_query(dataset, 'B', 'q2.png', (22, 198, 22), {'tags': ['clean']})
        report = evaluate(dataset, self.service)
        self.assertEqual(set(report['by_family']), {'screwdrivers', 'unknown'})
        self.assertEqual(report['by_family']['screwdrivers']['top_1_accuracy'], 1.0)
        self.assertEqual(report['by_tag']['handheld']['top_1_accuracy'], 1.0)
        self.assertEqual(report['by_tag']['clean']['top_1_accuracy'], 1.0)
        self.assertEqual(report['queries'][0]['expected_families'], ['screwdrivers'])

    def test_failed_top1_carries_error_analysis_without_image_bytes(self):
        self.add('A', 'a', (200, 20, 20), family='screwdrivers')
        self.add('B', 'b', (202, 22, 22), family='screwdrivers')
        dataset = self.root / 'evaluation'
        # Query sits between the two references; either ranking is informative.
        self.write_query(dataset, 'B', 'q1.png', (201, 21, 21), {'capture_group': 'eval-x'})
        report = evaluate(dataset, self.service)
        row = report['queries'][0]
        self.assertIn('predicted_sku', row)
        self.assertIn('ambiguous', row)
        self.assertIn('ambiguity_reason', row)
        self.assertEqual(len(report['errors']), 0 if row['rank'] == 1 else 1)
        if report['errors']:
            error = report['errors'][0]
            for key in ('query', 'expected_sku', 'predicted_sku', 'rank', 'predicted',
                        'ambiguous', 'ambiguity_reason', 'capture_group', 'tags'):
                self.assertIn(key, error)
            for key in ('sku', 'score', 'visual_score', 'image_id', 'matched_images'):
                self.assertIn(key, error['predicted'])
        blob = json.dumps(report)
        self.assertNotIn('"master":', blob)
        self.assertNotIn('base64', blob.lower())

    def test_human_summary_reports_key_numbers(self):
        self.add('A', 'a', (200, 20, 20))
        dataset = self.root / 'evaluation'
        self.write_query(dataset, 'A', 'q1.png', (198, 22, 22))
        report = evaluate(dataset, self.service)
        summary = format_summary(report)
        for fragment in ('images=1', 'top-1=1.000', 'mrr=1.000', 'latency', report['dataset_version']):
            self.assertIn(fragment, summary)


if __name__ == '__main__':
    unittest.main()
