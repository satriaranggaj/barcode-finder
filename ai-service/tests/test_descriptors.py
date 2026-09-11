import unittest
from PIL import Image, ImageDraw
from app.preprocessing import prepare
from app.descriptors import describe


class DescriptorTest(unittest.TestCase):
    def test_color_and_proportion_distinguish_objects(self):
        def features(color, box):
            image = Image.new('RGB', (300, 200), 'white')
            ImageDraw.Draw(image).rectangle(box, fill=color)
            return describe(prepare(image))
        a = features('blue', (70, 50, 230, 150))
        b = features('red', (110, 30, 190, 170))
        self.assertNotEqual(a['color'], b['color'])
        self.assertGreater(a['proportion'], b['proportion'])
        self.assertEqual(len(a['shape']), 16)
        self.assertEqual(len(a['texture']), 24)
        self.assertLessEqual(len(a['local']), 32)
        self.assertAlmostEqual(sum(a['color']), 1, places=4)

    def test_fallback_does_not_invent_shape(self):
        result = describe(prepare(Image.new('RGB', (100, 100), 'gray')))
        self.assertIsNone(result['shape'])
        self.assertIsNone(result['proportion'])


if __name__ == '__main__':
    unittest.main()
