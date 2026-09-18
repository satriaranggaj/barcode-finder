import json
import subprocess
import sys
import tempfile
import unittest
from unittest.mock import patch
from contextlib import redirect_stdout
from io import StringIO
from pathlib import Path
from io import BytesIO
from PIL import Image
from fastapi.testclient import TestClient
from app.main import create_app
from app.config import Settings
from app.features.ocr import OcrResult, OcrWord
from app.scripts.calibrate import ranking_metrics
import test_retrieval as fixtures


class WorkflowTests(unittest.TestCase):
    def test_comparison_runs_all_seven_scenarios_with_isolated_indexes(self):
        import numpy as np
        from app.scripts.compare import main
        class Encoder(fixtures.MockEncoder):
            def encode_text(self, text): return np.array([1.,0.,0.],dtype='float32')
            def encode_patches(self, image, limit): return np.tile(self.encode_image(image),(8,1))
        with tempfile.TemporaryDirectory() as directory:
            root=Path(directory); references=root/'references'/'A'; query=root/'queries'/'A'
            references.mkdir(parents=True); query.mkdir(parents=True)
            Image.new('RGB',(64,64),(200,10,10)).save(references/'one.png')
            Image.new('RGB',(64,64),(190,15,15)).save(references/'two.png')
            (references/'one.json').write_text(json.dumps({'description':'PH2 150MM','crop':{'x':.1,'y':.1,'width':.8,'height':.8}}))
            (references/'two.json').write_text(json.dumps({'description':'PH2 150MM'}))
            Image.new('RGB',(64,64),(180,20,20)).save(query/'query.png')
            (query/'query.json').write_text(json.dumps({'relevant_skus':['A'],'capture_group':'heldout'}))
            args=['compare','--references',str(references.parent),'--evaluation',str(query.parent),'--output',str(root/'reports'),
                  '--patch-weight','.2','--secondary-weight','.1','--description-weight','.5','--ocr']
            with patch.object(sys,'argv',args), patch('app.scripts.compare.load_encoders',return_value={'siglip':Encoder(),'dino':Encoder()}), \
                 patch('shutil.which',return_value='test-ocr'), patch('app.features.ocr.TesseractOCR.extract_words',return_value=OcrResult(words=(OcrWord(text='PH2',tokens=('PH2',),confidence=95.0,box=None),),engine='test-ocr')), redirect_stdout(StringIO()):
                main()
            summary=json.loads((root/'reports/summary.json').read_text())
            self.assertEqual(len(summary),7)
            self.assertEqual(summary['A-global']['reference_policy'],'one_per_sku')
            self.assertEqual(summary['E-multiple-references']['reference_policy'],'all_references')
            result=json.loads((root/'reports/G-ocr.json').read_text())
            self.assertIsNotNone(result['queries'][0]['results'][0]['ocr_score'])
            # Same evaluation split for every scenario; each report carries its
            # own signature, and run metadata stays out of the scenario mapping.
            versions={name:report['dataset_version'] for name,report in summary.items()}
            self.assertEqual(len(set(versions.values())),1)
            for name in ('A-global','B-existing-dual','C-selected-object','D-patch',
                         'E-multiple-references','F-description','G-ocr'):
                report=json.loads((root/'reports'/f'{name}.json').read_text())
                self.assertIn('evaluation_signature',report)
                for metric in ('top_1_accuracy','top_3_accuracy','top_5_accuracy','mrr',
                               'median_latency_ms','p95_latency_ms'):
                    self.assertIn(metric,report)
            run=json.loads((root/'reports/run.json').read_text())
            self.assertEqual(run['scenarios'],['A-global','B-existing-dual','C-selected-object','D-patch',
                                               'E-multiple-references','F-description','G-ocr'])
            self.assertEqual(run['patch_weight'],.2)
            self.assertTrue(run['ocr'])

    def test_calibration_cli_end_to_end(self):
        examples=Path(__file__).resolve().parents[1]/'benchmarks/examples'
        with tempfile.TemporaryDirectory() as directory:
            root=Path(directory)
            commands=[['tune','--input',str(examples/'tuning.json'),'--output',str(root/'tuning.json')],
                      ['freeze','--input',str(root/'tuning.json'),'--output',str(root/'policy.json')],
                      ['evaluate','--input',str(examples/'evaluation.json'),'--policy',str(root/'policy.json'),'--output',str(root/'report.json')]]
            for args in commands:
                subprocess.run([sys.executable,'-B','-m','app.scripts.calibrate',*args],check=True,capture_output=True)
            report=json.loads((root/'report.json').read_text())
            for key in ('top_1_accuracy','top_5_accuracy','mrr','recall_at_5','tp','fp','tn','fn','precision','positive_recall','false_positive_rate','false_negative_rate','negative_rejection','positive_query_false_rejection','median_latency_ms','p95_latency_ms'):
                self.assertIn(key,report)

    def test_prepare_api_preserves_memory_guard_and_rejects_corruption(self):
        settings=Settings(legacy_embed=False,processing_memory_mb=20)
        stream=BytesIO(); Image.new('RGB',(1000,800),'red').save(stream,'JPEG')
        # Supply a lightweight initialized service so no real models are loaded.
        from app.search.service import RetrievalService
        service=RetrievalService(settings,{'siglip':fixtures.MockEncoder(),'dino':fixtures.MockEncoder()})
        with TestClient(create_app(settings,service)) as client:
            result=client.post('/prepare',files={'image':('photo.jpg',stream.getvalue())})
            self.assertEqual(result.status_code,200)
            self.assertEqual(result.json()['status'],'preserved_memory_budget')
            self.assertEqual(client.post('/prepare',files={'image':('bad.jpg',b'not an image')}).status_code,422)

    def test_top5_ranking_and_latency(self):
        rows=[{'relevant_skus':['B','C'],'results':[{'sku':'X','score':.9},{'sku':'B','score':.8}], 'latency_ms':100},
              {'relevant_skus':[],'results':[], 'latency_ms':200}]
        report=ranking_metrics(rows)
        self.assertEqual(report['top_1_accuracy'],0)
        self.assertEqual(report['top_5_accuracy'],1)
        self.assertEqual(report['recall_at_5'],.5)
        self.assertEqual(report['mrr'],.5)
        self.assertEqual(report['median_latency_ms'],150)
        self.assertEqual(report['p95_latency_ms'],195)
