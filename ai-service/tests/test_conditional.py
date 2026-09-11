import unittest
from unittest.mock import Mock
import numpy as np
from PIL import Image, ImageDraw
from app.conditional_preprocessing import prepare


class ConditionalTest(unittest.TestCase):
    def test_clean_background_skips_grabcut(self):
        image = Image.new('RGB',(256,256),'white')
        ImageDraw.Draw(image).rectangle((80,60,180,190),fill='blue')
        segmenter = Mock(side_effect=AssertionError('expensive segmentation called'))
        result = prepare(image,segmenter)
        self.assertEqual(result.metadata['mode'],'clean_crop')
        self.assertEqual(result.metadata['timings']['grabcut_ms'],0)
        segmenter.assert_not_called()

    def test_border_filling_or_fragmented_foreground_is_not_cropped(self):
        rng = np.random.default_rng(42)
        image = Image.fromarray(rng.integers(0,256,(256,256,3),dtype=np.uint8))
        border_mask = np.zeros((256,256),np.uint8)
        border_mask[2:180,30:180] = 1
        split_mask = np.zeros((256,256),np.uint8)
        split_mask[50:150,30:100] = 1
        split_mask[50:150,150:220] = 1
        for mask in (border_mask,split_mask):
            result = prepare(image,lambda _: mask)
            self.assertEqual(result.metadata['mode'],'original')
            self.assertTrue(result.metadata['geometry']['failed_checks'])
            self.assertEqual(result.metadata['bbox'],[0,0,256,256])

    def test_segmentation_error_falls_back_with_no_internal_error_details(self):
        image = Image.fromarray(np.random.default_rng(4).integers(0,256,(128,128,3),dtype=np.uint8))
        segmenter = Mock(side_effect=RuntimeError('/private/internal/path'))
        result = prepare(image,segmenter)
        self.assertEqual(result.metadata['mode'],'original')
        self.assertNotIn('private',str(result.metadata))

    def test_geometric_metrics_are_recorded_without_probability_claim(self):
        image = Image.new('RGB',(256,256),'white')
        ImageDraw.Draw(image).ellipse((80,60,180,190),fill='red')
        result = prepare(image)
        self.assertEqual(result.metadata['geometry']['failed_checks'],[])
        self.assertIn('compactness',result.metadata['geometry'])
        self.assertNotIn('probability',result.metadata)
