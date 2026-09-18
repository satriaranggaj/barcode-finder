from dataclasses import replace
import json
import unittest
from unittest.mock import patch
import numpy as np
from PIL import Image
from app.features.attributes import parse_attributes, compatibility
from app.features.ocr import DisabledOCR, TesseractOCR
from app.preprocessing.selection import BoundingBox
from app.search.ranking import patch_similarity
import test_retrieval as fixtures
MockEncoder = fixtures.MockEncoder


class ObjectRetrievalTests(unittest.TestCase):
    setUp = fixtures.RetrievalTests.setUp
    tearDown = fixtures.RetrievalTests.tearDown
    def test_frozen_policy_is_bound_to_published_reference_snapshot(self):
        from app.search.faiss_index import FaissIndexManager
        from app.search.service import RetrievalService
        image=Image.new('RGB',(64,64),'red')
        vectors,_=self.service.embed(image,'original')
        self.index.add_product_embedding({'sku':'A','image_id':'a','photo_hash':'a'*64},vectors)
        self.index.save_index(self.root/'one')
        first=FaissIndexManager.load_index(self.root/'one')
        try:
            serving=RetrievalService(self.settings,self.encoders,first)
            policy=self.root/'policy.json'
            policy.write_text(json.dumps({'frozen':True,'threshold':.8,'signature':serving.evaluation_signature}))
            configured=replace(self.settings,relevance_policy=str(policy))
            RetrievalService(configured,self.encoders,first)
            self.index.add_product_embedding({'sku':'B','image_id':'b','photo_hash':'b'*64},vectors)
            self.index.save_index(self.root/'two')
            second=FaissIndexManager.load_index(self.root/'two')
            try:
                with self.assertRaisesRegex(ValueError,'incompatible'):
                    RetrievalService(configured,self.encoders,second)
            finally: second.close()
        finally: first.close()
    def test_failed_selection_uses_original_without_second_detection(self):
        with patch('app.preprocessing.selection.propose', return_value={'boxes':[], 'reason':'no_object'}) as proposal:
            with patch.object(self.service.preprocessor, 'prepare', wraps=self.service.preprocessor.prepare) as prepare:
                _, info = self.service.embed(Image.new('RGB',(64,64),'red'), 'object')
        proposal.assert_called_once()
        self.assertIsNone(info['selection_used'])
        self.assertTrue(all(call.args[1] == 'original' for call in prepare.call_args_list))

    def test_frozen_policy_rejects_missing_model(self):
        self.service.policy = {'threshold':.8}
        with patch.object(self.service.encoders['dino'], 'encode_images', side_effect=RuntimeError('unavailable')):
            with self.assertRaisesRegex(RuntimeError, 'complete, unfiltered'):
                self.service.search(Image.new('RGB',(64,64),'red'), mode='original')

    def test_frozen_policy_rejects_missing_patch_evidence(self):
        self.service.settings = replace(self.settings, patch_weight=.2)
        self.service.policy = {'threshold': .8}
        with self.assertRaisesRegex(RuntimeError, 'complete patch'):
            self.service.search(Image.new('RGB', (64, 64), 'red'), mode='original')

    def test_object_and_context_differ_and_manual_crop_is_reproducible(self):
        image = Image.new('RGB',(100,100),'blue')
        image.paste('red',(0,0,50,100))
        box = BoundingBox(0,0,.5,1)
        query, info = self.service.embed(image, 'object', box)
        reference, _ = self.service.embed(image, 'object', box)
        np.testing.assert_allclose(query['dino']['global'], reference['dino']['global'])
        self.assertFalse(np.allclose(query['dino']['global'], query['dino']['context']))
        self.assertEqual(info['selection_used']['width'], .5)

    def test_patch_similarity_is_symmetric_and_accidental_patch_is_not_enough(self):
        q = np.tile([1.,0.], (16,1))
        bad = np.tile([0.,1.], (16,1)); bad[0] = [1.,0.]
        self.assertAlmostEqual(patch_similarity(q,bad), patch_similarity(bad,q))
        self.assertGreater(patch_similarity(q,q), patch_similarity(q,bad))

    def test_patch_vectors_roundtrip_only_for_shortlist(self):
        class Patches(MockEncoder):
            def encode_patches(self, image, limit):
                return np.tile(self.encode_image(image),(8,1))
        from app.search.service import RetrievalService
        from app.search.faiss_index import FaissIndexManager
        settings = replace(self.settings, patch_weight=.2)
        service = RetrievalService(settings, {'siglip':MockEncoder(),'dino':Patches()})
        index = FaissIndexManager(self.root/'patch.sqlite', service.signature)
        try:
            service.index = index
            image = Image.new('RGB',(64,64),'red')
            vectors,_ = service.embed(image,'original')
            index.add_product_embedding({'sku':'001','image_id':'one','photo_hash':'test'}, vectors)
            result = service.search(image,mode='original')
            self.assertIsNotNone(result['results'][0]['local_score'])
            self.assertEqual(index.reference(0)[1]['dino']['patches'].shape,(8,3))
            index.save_index(self.root/'patch-generation')
            loaded = FaissIndexManager.load_index(self.root/'patch-generation'); loaded.close()
        finally: index.close()

    def test_description_parser_and_optional_ocr(self):
        attributes = parse_attributes('PH2 150MM 1.5" SIZE 40 BLACK 2PCS JC414')
        for field in ('drive','measurement','size','color','quantity','model'):
            self.assertIn(field,attributes)
        self.assertIsNone(compatibility({},attributes))
        self.assertEqual(compatibility({'drive':['PH1']},{'drive':['PH2']}),0)
        image = Image.new('RGB',(64,64))
        self.assertEqual(DisabledOCR().extract_text(image),[])
        self.assertEqual(TesseractOCR('nonexistent-lensku-ocr').extract_text(image),[])

    def test_search_survives_total_selection_failure(self):
        from app.preprocessing.selection import SelectionPipeline
        from app.search.service import RetrievalService
        class Dead:
            def select(self, image): raise RuntimeError('unavailable')
        service = RetrievalService(self.settings, self.encoders, pipeline=SelectionPipeline(Dead(), Dead()))
        _, info = service.embed(Image.new('RGB',(64,64),'red'), 'object')
        self.assertEqual(info['reason'], 'full_image_fallback')
        self.assertIsNone(info['selection_used'])
        self.assertEqual(info['models'], list(service.encoders))

    def test_selector_can_be_swapped_without_touching_search_pipeline(self):
        from app.preprocessing.selection import BoundingBox, Candidate, SelectionPipeline
        from app.search.service import RetrievalService
        class CustomSelector:
            def select(self, image):
                return [Candidate(BoundingBox(0, 0, .5, .5), 'custom_selector', .25)]
        service = RetrievalService(self.settings, self.encoders,
                                   pipeline=SelectionPipeline(CustomSelector()))
        _, info = service.embed(Image.new('RGB',(64,64),'red'), 'object')
        self.assertEqual(info['reason'], 'foreground_proposal')
        self.assertEqual(info['selection_used']['width'], .5)
