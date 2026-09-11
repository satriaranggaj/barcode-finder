import copy
import hashlib
import json
from pathlib import Path
import subprocess
import sys
import tempfile
import unittest

from relevance_benchmark import evaluate, evaluate_frozen, fingerprint, freeze


ROOT = Path(__file__).resolve().parents[1]
EXAMPLES = ROOT / 'relevance-examples'


class RelevanceTest(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.folder = Path(self.temp.name)
        self.pipeline = json.loads((EXAMPLES / 'pipeline.json').read_text())

    def row(self, identity, relevant, candidates, split='tuning', latency=100):
        return dict(query_id=identity, capture_group='session-' + identity,
                    query_sha256=hashlib.sha256(identity.encode()).hexdigest(),
                    split=split, pipeline_sha256=fingerprint(self.pipeline), labels_complete=True,
                    relevant_skus=relevant, latency_ms=latency,
                    candidates=[dict(sku=sku, raw_cosine=value, final_score=value) for sku, value in candidates])

    def dataset(self, rows, filename='rows.jsonl'):
        path = self.folder / filename
        path.write_text(''.join(json.dumps(row) + '\n' for row in rows), encoding='utf-8')
        return path

    def tuning(self):
        return evaluate(self.dataset([self.row('positive', ['A'], [('A', .9), ('X', .5)]),
                                      self.row('negative', [], [('X', .3)])]), self.pipeline, [.4, .6], 'tuning')

    def test_tuning_requires_positive_query(self):
        with self.assertRaisesRegex(ValueError, 'positive AND'):
            evaluate(self.dataset([self.row('n', [], [])]), self.pipeline, [.6], 'tuning')

    def test_tuning_requires_negative_query(self):
        with self.assertRaisesRegex(ValueError, 'positive AND'):
            evaluate(self.dataset([self.row('p', ['A'], [('A', 1)])]), self.pipeline, [.6], 'tuning')

    def test_duplicate_query_id_and_hash_are_rejected(self):
        for field in ('query_id', 'query_sha256'):
            with self.subTest(field=field):
                a = self.row('a', ['A'], [])
                b = self.row('b', [], [])
                b[field] = a[field]
                with self.assertRaisesRegex(ValueError, 'Duplicate'):
                    evaluate(self.dataset([a, b]), self.pipeline, [.6], 'tuning')

    def test_each_tuning_identity_leakage_is_rejected(self):
        policy = freeze(self.tuning(), .6)
        for field in ('query_id', 'capture_group', 'query_sha256'):
            with self.subTest(field=field):
                row = self.row('held-out', ['A'], [('A', .9)], 'evaluation')
                row[field] = policy['tuning_identities'][field][0]
                with self.assertRaisesRegex(ValueError, 'leakage'):
                    evaluate_frozen(self.dataset([row]), policy, self.pipeline)

    def test_freeze_only_allows_threshold_tested_on_tuning(self):
        report = self.tuning()
        with self.assertRaisesRegex(ValueError, 'not tested'):
            freeze(report, .7)
        self.assertEqual(freeze(report, .6)['final_min_score'], .6)
        report['split'] = 'evaluation'
        with self.assertRaisesRegex(ValueError, 'tuning report'):
            freeze(report, .6)

    def test_changed_pipeline_or_catalog_rejects_frozen_policy(self):
        policy = freeze(self.tuning(), .6)
        path = self.dataset([self.row('heldout', [], [], 'evaluation')])
        for field, value in [('model_revision', 'different'), ('k', 30), ('ef_search', 200),
                             ('confidence_margin', .1), ('descriptor_weights', {'global': 1, 'color': .2}),
                             ('preprocessing_version', 'different'), ('catalog_sha256', 'a' * 64)]:
            with self.subTest(field=field):
                pipeline = copy.deepcopy(self.pipeline)
                pipeline[field] = value
                with self.assertRaisesRegex(ValueError, 'incompatible'):
                    evaluate_frozen(path, policy, pipeline)

    def test_modified_frozen_threshold_is_rejected(self):
        policy = freeze(self.tuning(), .6)
        policy['final_min_score'] = .4
        with self.assertRaisesRegex(ValueError, 'modified'):
            evaluate_frozen(self.dataset([]), policy, self.pipeline)

    def test_negative_with_empty_or_filtered_results_is_true_rejection(self):
        data = [self.row('n1', [], [], 'evaluation'), self.row('n2', [], [('X', .2)], 'evaluation')]
        report = evaluate(self.dataset(data), self.pipeline, [.6], 'evaluation')
        count = report['thresholds'][0]
        self.assertEqual(count['rejected_negative_queries'], 2)
        self.assertEqual(count['negative_rejection'], 1)
        self.assertEqual((count['tp'], count['fp'], count['tn'], count['fn']), (0, 0, 1, 0))
        self.assertIsNone(report['top1'])
        self.assertIsNone(count['false_negative_rate'])

    def test_false_positives_include_wrong_sku_on_positive_query(self):
        data = [self.row('p', ['A'], [('A', 1), ('X', .8)]), self.row('n', [], [('Y', .7)])]
        report = evaluate(self.dataset(data), self.pipeline, [.6], 'tuning')
        count = report['thresholds'][0]
        self.assertEqual(report['top1'], 1)
        self.assertEqual((count['tp'], count['fp'], count['tn'], count['fn']), (1, 2, 0, 0))
        self.assertAlmostEqual(count['precision'], 1 / 3)
        self.assertEqual(count['false_positive_rate'], 1)
        self.assertEqual(count['negative_rejection'], 0)

    def test_false_negatives_include_filter_and_retrieval_misses(self):
        data = [self.row('p', ['A', 'B'], [('A', .3)]), self.row('n', [], [])]
        report = evaluate(self.dataset(data), self.pipeline, [.6], 'tuning')
        count = report['thresholds'][0]
        self.assertEqual(report['recall_at_k'], .5)
        self.assertEqual(count['fn'], 2)
        self.assertEqual(count['false_negative_rate'], 1)
        self.assertEqual(count['positive_recall'], 0)

    def test_all_filtered_positive_is_false_rejection_despite_top1_success(self):
        data = [self.row('p', ['A'], [('A', .5)]), self.row('n', [], [])]
        report = evaluate(self.dataset(data), self.pipeline, [.6], 'tuning')
        self.assertEqual(report['top1'], 1)
        self.assertEqual(report['thresholds'][0]['positive_query_false_rejection'], 1)

    def test_duplicates_group_by_sku_and_use_final_score(self):
        row = self.row('p', ['A'], [('A', .9), ('A', .8), ('X', .95)])
        row['candidates'][2]['final_score'] = .2
        report = evaluate(self.dataset([row, self.row('n', [], [])]), self.pipeline, [.6], 'tuning')
        count = report['thresholds'][0]
        self.assertEqual((count['tp'], count['fp'], count['tn'], count['fn']), (1, 0, 1, 0))
        self.assertEqual(report['top1'], 1)

    def test_report_metrics_and_latency(self):
        data = [self.row('p', ['A'], [('X', .9), ('A', .7)], latency=100),
                self.row('n', [], [('X', .2)], latency=200)]
        report = evaluate(self.dataset(data), self.pipeline, [.6], 'tuning')
        self.assertEqual((report['top1'], report['top5'], report['mrr'], report['recall_at_k']), (0, 1, .5, 1))
        self.assertEqual(report['median_ms'], 150)
        self.assertEqual(report['p95_ms'], 195)
        self.assertFalse(report['acceptance_ready'])

    def test_user_evidence_records_false_positives_without_runtime_rules(self):
        evidence = json.loads((ROOT.parent / 'docs/image-search/relevance-evidence.json').read_text())
        self.assertFalse(evidence['eligible_for_calibration'])
        records = []
        for case in evidence['cases']:
            self.assertIsNone(case['query_sha256'])
            if case['observed_candidates'] is None:
                continue  # Label-only evidence must not invent observed retrieval scores.
            # Rounded UI scores are test fixtures only, not a calibration export.
            records.append(self.row(case['id'], case['relevant_skus'],
                                    [(c['sku'], c['displayed_score']) for c in case['observed_candidates']], 'evaluation'))
        report = evaluate(self.dataset(records), self.pipeline, [.72], 'evaluation')
        self.assertEqual(report['top1'], 1)
        self.assertEqual(report['thresholds'][0]['tp'], 3)
        self.assertEqual(report['thresholds'][0]['fp'], 4)
        self.assertEqual(report['thresholds'][0]['fn'], 0)

    def test_dumbbell_evidence_is_out_of_catalog_with_zero_expected_results(self):
        evidence = json.loads((ROOT.parent / 'docs/image-search/relevance-evidence.json').read_text())
        case = next(c for c in evidence['cases'] if c['id'] == 'case-c-dumbbell-no-reference')
        self.assertEqual(case['relevant_skus'], [])
        self.assertEqual(case['expected_result_count'], 0)
        self.assertIsNone(case['observed_candidates'])
        self.assertIsNone(case['query_sha256'])

    def test_unknown_labels_bad_scores_and_wrong_split_are_rejected(self):
        for field, value in [('labels_complete', False), ('split', 'evidence'), ('latency_ms', True)]:
            row = self.row('a', ['A'], [('A', .5)])
            row[field] = value
            with self.subTest(field=field), self.assertRaises(ValueError):
                evaluate(self.dataset([row]), self.pipeline, [.6], 'tuning')
        row = self.row('a', ['A'], [('A', .5)])
        row['candidates'][0]['final_score'] = float('nan')
        with self.assertRaises(ValueError):
            evaluate(self.dataset([row]), self.pipeline, [.6], 'tuning')

    def test_cli_examples_end_to_end_and_no_runtime_changes(self):
        watched = [ROOT.parent / '.env', ROOT.parent / 'config/image_search.php']
        before = {p: p.read_bytes() for p in watched if p.exists()}
        report, policy, output = [self.folder / name for name in ('tune.json', 'policy.json', 'evaluation.json')]
        def run(*args):
            return subprocess.run([sys.executable, '-B', str(ROOT / 'relevance_benchmark.py'), *map(str, args)], capture_output=True, text=True)
        result = run('tune', EXAMPLES / 'tuning.jsonl', '--pipeline', EXAMPLES / 'pipeline.json', '--thresholds', '.4,.6', '--output', report)
        self.assertEqual(result.returncode, 0, result.stderr)
        result = run('freeze', report, '--threshold', '.6', '--output', policy)
        self.assertEqual(result.returncode, 0, result.stderr)
        result = run('evaluate', EXAMPLES / 'evaluation.jsonl', '--policy', policy, '--pipeline', EXAMPLES / 'pipeline.json', '--output', output)
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(len(json.loads(output.read_text())['thresholds']), 1)
        result = run('evaluate', EXAMPLES / 'evaluation.jsonl', '--policy', policy, '--pipeline', EXAMPLES / 'pipeline.json', '--thresholds', '.3,.6', '--output', self.folder / 'retuned.json')
        self.assertNotEqual(result.returncode, 0)
        self.assertFalse((self.folder / 'retuned.json').exists())
        result = run('freeze', report, '--threshold', '.4', '--output', policy)
        self.assertNotEqual(result.returncode, 0)  # Cannot overwrite frozen output.
        for path, content in before.items():
            self.assertEqual(path.read_bytes(), content)


if __name__ == '__main__':
    unittest.main()
