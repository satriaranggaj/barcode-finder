import copy
import unittest
from app.scripts.calibrate import tune, freeze, evaluate_policy, metrics


class CalibrationTests(unittest.TestCase):
    def test_sweep_matches_full_metrics_at_every_boundary(self):
        report = self.report()
        report['queries'][0]['relevant_skus'].append('missing')
        report['queries'][1]['results'].append({'sku':'C','score':.8})
        for trial in tune(report)['trials']:
            self.assertEqual({k:v for k,v in trial.items() if k != 'threshold'},
                             metrics(report['queries'], trial['threshold']))

    def test_negative_only_heldout_is_valid(self):
        policy = freeze(tune(self.report()), .8)
        report = self.report()
        report['queries'] = [{'relevant_skus':[], 'results':[],
                              'photo_hash':'c'*64, 'capture_group':'heldout'}]
        self.assertEqual(evaluate_policy(policy, report)['negative_rejection'], 1)

    def test_filtered_candidates_cannot_be_used_to_tune(self):
        report = self.report(); report['gate_applied'] = True
        with self.assertRaises(ValueError): tune(report)

    def test_duplicate_photo_and_candidate_are_rejected(self):
        report = self.report()
        report['queries'][1]['photo_hash'] = 'A'*64
        with self.assertRaises(ValueError): tune(report)
        report = self.report()
        report['queries'][0]['results'].append({'sku':'A','score':.7})
        with self.assertRaises(ValueError): tune(report)

    def report(self):
        return {'evaluation_signature': {'pipeline':'test'}, 'queries':[
            {'relevant_skus':['A'], 'results':[{'sku':'A','score':.8},{'sku':'B','score':.5}], 'photo_hash':'a'*64,'capture_group':'p'},
            {'relevant_skus':[], 'results':[{'sku':'B','score':.6}], 'photo_hash':'b'*64,'capture_group':'n'}]}
    def test_positive_negative_required(self):
        for i in (0,1):
            report=self.report(); report['queries']=[report['queries'][i]]
            with self.assertRaises(ValueError): tune(report)
    def test_freeze_tested_only_and_heldout(self):
        report=self.report(); tuning=tune(report)
        with self.assertRaises(ValueError): freeze(tuning,.123)
        policy=freeze(tuning,.8)
        with self.assertRaises(ValueError): evaluate_policy(policy,report)
        report['queries'][0].update(photo_hash='c'*64,capture_group='e1')
        report['queries'][1].update(photo_hash='d'*64,capture_group='e2')
        self.assertEqual(evaluate_policy(policy,report)['negative_rejection'],1)
        report['evaluation_signature']={'pipeline':'changed'}
        with self.assertRaises(ValueError): evaluate_policy(policy,report)
    def test_metrics_false_positive_negative_and_false_rejection(self):
        report=self.report()
        self.assertEqual(metrics(report['queries'],.5)['fp'],2)
        self.assertEqual(metrics(report['queries'],.9)['fn'],1)
        self.assertEqual(metrics(report['queries'],.9)['positive_query_false_rejection'],1)
        self.assertEqual(metrics(report['queries'],.9)['tn'],1)
