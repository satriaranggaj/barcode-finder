import io
import unittest
from unittest.mock import patch
import numpy as np
from PIL import Image, ImageDraw
from app.preprocessing import decode, prepare


class PreprocessingTest(unittest.TestCase):
    def test_object_crop_and_normalization(self):
        image = Image.new("RGB", (300, 200), "white")
        mask = np.zeros((200, 300), np.uint8)
        mask[50:150, 100:200] = 1
        result = prepare(image, lambda _: mask)
        self.assertEqual(result.metadata["mode"], "segmented")
        self.assertEqual(result.image.width, result.image.height)
        self.assertLess(result.image.width, 300)
        self.assertGreater(result.mask.sum(), 0)

    def test_failure_empty_and_ambiguous_mask_fall_back(self):
        image = Image.new("RGB", (100, 80), "red")
        def fail(_):
            raise RuntimeError("secret internal path")
        for segmenter in (fail, lambda _: np.zeros((80, 100), np.uint8), lambda _: np.ones((80, 100), np.uint8)):
            result = prepare(image, segmenter)
            self.assertEqual(result.metadata["mode"], "original")
            self.assertNotIn("secret", str(result.metadata))
            self.assertEqual(result.image.getpixel((50, 50)), (255, 0, 0))

    def test_real_segmentation(self):
        image = Image.new("RGB", (300, 200), "white")
        ImageDraw.Draw(image).ellipse((90, 40, 210, 160), fill="blue")
        self.assertEqual(prepare(image).metadata["mode"], "segmented")

    def test_corrupt_and_animated_rejected(self):
        for content in (b"bad", b"", b"RIFF1234WEBP"):
            with self.assertRaises(ValueError):
                decode(content)
        buffer = io.BytesIO()
        Image.new("RGB", (10, 10)).save(buffer, format="PNG")
        with self.assertRaises(ValueError):
            decode(buffer.getvalue()[:-15])

    def test_reference_and_query_are_deterministic(self):
        image = Image.new("RGB", (300, 200), "white")
        ImageDraw.Draw(image).rectangle((100, 50, 200, 150), fill="green")
        self.assertEqual(prepare(image).image.tobytes(), prepare(image).image.tobytes())

    def test_new_budget_does_not_break_existing_legacy_upload(self):
        buffer = io.BytesIO()
        Image.new('RGB', (1000, 1000), 'red').save(buffer, format='PNG')
        with patch.dict('os.environ', {'AI_DECODE_BUDGET_MB': '1'}):
            with self.assertRaisesRegex(ValueError, 'decode_budget'):
                decode(buffer.getvalue())
            image, _ = decode(buffer.getvalue(), legacy=True)
            self.assertEqual(image.size, (1000, 1000))

    def test_smartphone_jpeg_uses_bounded_draft_decode(self):
        buffer = io.BytesIO()
        with Image.new('RGB', (4000, 3000), 'blue') as source:
            source.save(buffer, format='JPEG')
        image, _ = decode(buffer.getvalue())
        self.assertLess(image.width * image.height, 4_000_000)


if __name__ == "__main__":
    unittest.main()
