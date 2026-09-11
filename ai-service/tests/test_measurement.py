import unittest
import numpy as np
from PIL import Image
from app.preprocessing import cv2
from app.measurement import measure


class MeasurementTest(unittest.TestCase):
    def scene(self):
        canvas = np.full((500,700),255,np.uint8)
        marker = cv2.aruco.generateImageMarker(cv2.aruco.getPredefinedDictionary(cv2.aruco.DICT_4X4_50),0,100)
        canvas[50:150,50:150] = marker
        return Image.fromarray(canvas).convert('RGB')

    def test_known_planar_size(self):
        result = measure(self.scene(),5,[[250,200],[448,200],[448,299],[250,299]],True)
        self.assertAlmostEqual(result['long_side_cm'],10,delta=.1)
        self.assertAlmostEqual(result['short_side_cm'],5,delta=.1)

    def test_missing_scale_or_coplanarity_never_returns_centimeters(self):
        with self.assertRaisesRegex(ValueError,'coplanar'):
            measure(self.scene(),5,[[250,200],[448,200],[448,299],[250,299]],False)
        with self.assertRaisesRegex(ValueError,'marker_required'):
            measure(Image.new('RGB',(700,500),'white'),5,[[250,200],[448,200],[448,299],[250,299]],True)

    def test_crossed_corners_rejected(self):
        with self.assertRaises(ValueError):
            measure(self.scene(),5,[[250,200],[448,299],[448,200],[250,299]],True)
