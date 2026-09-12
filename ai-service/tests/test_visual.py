import io
import unittest
from types import SimpleNamespace
import numpy as np
from PIL import Image
from app.visual import decode, focus, descriptors


class Session:
    def __init__(self, output): self.output = output
    def get_inputs(self): return [SimpleNamespace(name='input')]
    def run(self, _, inputs): return [self.output[None,None]]


class VisualTest(unittest.TestCase):
    def test_camera_jpeg_is_safely_downsampled(self):
        contents = io.BytesIO()
        Image.new('RGB',(6000,4000),'red').save(contents,format='JPEG')
        image = decode(contents.getvalue())
        self.assertLess(image.width,6000)
        self.assertAlmostEqual(image.width/image.height,1.5)

    def test_invalid_bytes_rejected(self):
        for data in (b'',b'not an image'):
            with self.assertRaises((ValueError,OSError)): decode(data)

    def test_orientation_applied(self):
        contents = io.BytesIO()
        image = Image.new('RGB',(80,40),'red')
        exif = image.getexif(); exif[274] = 6
        image.save(contents,format='JPEG',exif=exif)
        self.assertEqual(decode(contents.getvalue()).size,(40,80))

    def test_empty_mask_preserves_whole_product(self):
        image,mask,meta = focus(Image.new('RGB',(100,50),'red'),Session(np.zeros((320,320),np.float32)))
        self.assertEqual(image.size,(100,100)); self.assertIsNone(mask)
        self.assertEqual(meta['focus'],'full_frame')

    def test_shared_reference_query_features_are_deterministic(self):
        output = np.zeros((320,320),np.float32); output[30:280,100:220] = 1
        original = Image.new('RGB',(400,500),'blue')
        first = focus(original,Session(output)); second = focus(original,Session(output))
        self.assertEqual(descriptors(*first[:2]),descriptors(*second[:2]))
        self.assertEqual(original.size,(400,500))
        self.assertEqual(first[2]['hand_separation'],'not_guaranteed')

    def test_transparency_is_composited_and_corrupt_mask_is_technical_error(self):
        contents = io.BytesIO(); Image.new('RGBA',(20,20),(0,0,0,0)).save(contents,format='PNG')
        self.assertEqual(decode(contents.getvalue()).getpixel((0,0)),(127,127,127))
        with self.assertRaises(RuntimeError): focus(Image.new('RGB',(40,40)),Session(np.full((320,320),np.nan)))


if __name__ == '__main__': unittest.main()
