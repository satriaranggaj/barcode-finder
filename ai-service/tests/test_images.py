import unittest
import base64
import struct
import zlib
from io import BytesIO
import numpy as np
from PIL import Image
from PIL.TiffImagePlugin import IFDRational
from fastapi.testclient import TestClient
from app.config import Settings
from app.main import create_app
from app.preprocessing.images import image_variants, prepare_upload
from app.preprocessing.pipeline import InvalidImage, decode_image, flatten_alpha


def encode(image: Image.Image, fmt='PNG', **kwargs) -> bytes:
    stream = BytesIO()
    image.save(stream, fmt, **kwargs)
    return stream.getvalue()


def decode(data: str) -> Image.Image:
    return Image.open(BytesIO(base64.b64decode(data)))


def noise(width, height):
    return Image.fromarray(np.random.default_rng(7).integers(0, 256, (height, width, 3), dtype=np.uint8))


class ImagePipelineTests(unittest.TestCase):
    def test_jpeg_upload_keeps_resolution_and_scales_derivatives(self):
        data = encode(noise(2000, 1500), 'JPEG', quality=95)
        result = prepare_upload(data, 10_000_000, 10_000_000, 512)
        self.assertEqual(result['status'], 'optimized')
        self.assertEqual(result['extension'], 'webp')
        variants = {name: decode(result[name]) for name in ('master', 'catalog', 'thumbnail')}
        self.assertEqual(variants['master'].size, (2000, 1500))
        self.assertEqual(variants['catalog'].size, (1600, 1200))
        self.assertEqual(variants['thumbnail'].size, (400, 300))
        sizes = [len(base64.b64decode(result[name])) for name in ('master', 'catalog', 'thumbnail')]
        self.assertGreater(sizes[0], sizes[1])
        self.assertGreater(sizes[1], sizes[2])

    def test_png_alpha_flattens_over_white(self):
        rgba = Image.new('RGBA', (200, 100))
        rgba.paste((0, 0, 0, 0), (0, 0, 100, 100))
        rgba.paste((255, 0, 0, 128), (100, 0, 200, 100))
        result = prepare_upload(encode(rgba), 2_000_000, 2_000_000, 512)
        master = decode(result['master'])
        self.assertEqual(master.mode, 'RGB')
        self.assertEqual(master.getpixel((50, 50)), (255, 255, 255))
        blended = master.getpixel((150, 50))
        for channel, expected in zip(blended, (255, 127, 127)):
            self.assertLessEqual(abs(channel - expected), 10)

    def test_decode_image_flattens_alpha_for_ai_input(self):
        rgba = Image.new('RGBA', (200, 100))
        rgba.paste((0, 0, 0, 0), (0, 0, 100, 100))
        rgba.paste((255, 0, 0, 128), (100, 0, 200, 100))
        decoded = decode_image(encode(rgba), 2_000_000, 2_000_000)
        self.assertEqual(decoded.mode, 'RGB')
        canvas = Image.new('RGBA', rgba.size, (255, 255, 255, 255))
        expected = Image.alpha_composite(canvas, rgba).convert('RGB')
        self.assertEqual(decoded.tobytes(), expected.tobytes())

    def test_portrait_exif_orientation_rotates_to_landscape(self):
        source = Image.new('RGB', (900, 1800), (10, 80, 190))
        exif = source.getexif()
        exif[274] = 6
        result = prepare_upload(encode(source, 'JPEG', exif=exif), 10_000_000, 10_000_000, 512)
        self.assertEqual(result['status'], 'optimized')
        self.assertEqual(decode(result['master']).size, (1800, 900))
        self.assertEqual(decode(result['catalog']).size, (1600, 800))
        self.assertEqual(decode(result['thumbnail']).size, (400, 200))

    def test_invalid_corrupt_and_unsupported_inputs(self):
        for data in (b'', b'not an image'):
            with self.assertRaises(InvalidImage):
                prepare_upload(data, 2_000_000, 2_000_000, 512)
        with self.assertRaises(InvalidImage):
            prepare_upload(encode(Image.new('P', (40, 40)), 'GIF'), 2_000_000, 2_000_000, 512)
        truncated = encode(noise(400, 300), 'JPEG', quality=90)[:-20]
        with self.assertRaises(InvalidImage):
            prepare_upload(truncated, 2_000_000, 2_000_000, 512)
        with self.assertRaises(InvalidImage):
            prepare_upload(encode(noise(400, 300), 'JPEG', quality=90), 100, 2_000_000, 512)

    def test_oversized_image_is_rejected_for_ai_but_preserved_for_storage(self):
        data = encode(Image.new('RGB', (6000, 6000), 'green'), 'JPEG', quality=90)
        with self.assertRaises(InvalidImage):
            decode_image(data, 10_000_000, 1_000_000)
        result = prepare_upload(data, 10_000_000, 1_000_000, 512)
        self.assertEqual(result['status'], 'preserved_pixel_limit')
        self.assertNotIn('catalog', result)
        self.assertEqual(decode(result['master']).size, (6000, 6000))

    def test_preserved_png_checks_pixels_even_when_chunk_crc_is_valid(self):
        data = encode(Image.new('RGB', (200, 100), 'green'))
        valid = prepare_upload(data, 2_000_000, 1, 256)
        self.assertEqual(valid['status'], 'preserved_pixel_limit')
        pos = 8
        while data[pos+4:pos+8] != b'IDAT':
            pos += int.from_bytes(data[pos:pos+4], 'big') + 12
        length = int.from_bytes(data[pos:pos+4], 'big')
        payload = b'IDAT' + b'not a deflate stream'
        corrupt = data[:pos] + struct.pack('>I', len(payload)-4) + payload + struct.pack('>I', zlib.crc32(payload)) + data[pos+length+12:]
        # Container checks pass, so only bounded stream validation catches this.
        Image.open(BytesIO(corrupt)).verify()
        with self.assertRaises(InvalidImage):
            prepare_upload(corrupt, 2_000_000, 1, 256)

    def test_gps_and_comment_metadata_stripped_in_both_paths(self):
        source = Image.new('RGB', (1000, 800), (30, 90, 150))
        exif = source.getexif()
        exif[274] = 6
        exif[270] = 'private comment'
        exif[34853] = {1: 'N', 2: (IFDRational(1, 1), IFDRational(2, 1)), 3: 'E', 4: (IFDRational(3, 1), IFDRational(4, 1))}
        data = encode(source, 'JPEG', exif=exif)
        preserved = prepare_upload(data, 2_000_000, 2_000_000, 20)
        self.assertEqual(preserved['status'], 'preserved_memory_budget')
        kept = dict(decode(preserved['master']).getexif())
        self.assertEqual(kept, {274: 6})
        optimized = prepare_upload(data, 2_000_000, 2_000_000, 512)
        self.assertEqual(optimized['status'], 'optimized')
        self.assertEqual(dict(decode(optimized['master']).getexif()), {})

    def test_custom_quality_and_dimension_config(self):
        result = prepare_upload(encode(noise(2000, 1500), 'JPEG', quality=95), 10_000_000, 10_000_000, 512,
                                catalog_side=1200, thumbnail_side=300)
        self.assertEqual(decode(result['catalog']).size, (1200, 900))
        self.assertEqual(decode(result['thumbnail']).size, (300, 225))
        self.assertEqual(decode(result['master']).size, (2000, 1500))

    def test_variant_quality_out_of_range_is_rejected(self):
        image = Image.new('RGB', (10, 10))
        for kwargs in ({'quality': 70}, {'thumbnail_quality': 96}, {'catalog_quality': 74}):
            with self.assertRaises(ValueError):
                image_variants(image, **kwargs)

    def test_flatten_alpha_handles_la_mode(self):
        flattened = flatten_alpha(Image.new('LA', (10, 10), (0, 0)))
        self.assertEqual(flattened.mode, 'RGB')
        self.assertEqual(flattened.getpixel((5, 5)), (255, 255, 255))

    def test_settings_image_config_defaults_and_validation(self):
        settings = Settings()
        self.assertEqual((settings.master_quality, settings.catalog_quality, settings.thumbnail_quality), (88, 83, 78))
        self.assertEqual((settings.catalog_side, settings.thumbnail_side), (1600, 400))
        for kwargs in ({'master_quality': 50}, {'catalog_side': 500}, {'thumbnail_side': 600}):
            with self.assertRaises(ValueError):
                Settings(**kwargs)

    def test_verified_candidate_reports_spread_and_geometry_metrics(self):
        from app.preprocessing.images import verified_candidate
        blank = verified_candidate(Image.new('RGB', (300, 200), (128, 128, 128)))
        textured = verified_candidate(noise(300, 200))
        for key in ('photo_hash', 'dhash', 'blur', 'gray_std', 'crop_pixels', 'min_side'):
            self.assertIn(key, blank)
        self.assertEqual(blank['crop_pixels'], 300 * 200)
        self.assertEqual(blank['min_side'], 200)
        self.assertAlmostEqual(blank['gray_std'], 0.0, places=6)
        self.assertGreater(textured['gray_std'], 30.0)
        self.assertGreater(textured['blur'], blank['blur'])

    def test_prepare_endpoint_enforces_quality_bounds(self):
        data = encode(Image.new('RGB', (120, 80), 'blue'))
        with TestClient(create_app(Settings(), object(), None)) as client:
            invalid = client.post('/prepare', files={'image': ('a.png', data, 'image/png')}, data={'quality': '50'})
            self.assertEqual(invalid.status_code, 422)
            valid = client.post('/prepare', files={'image': ('a.png', data, 'image/png')}, data={'quality': '90'})
            self.assertEqual(valid.status_code, 200)
            self.assertEqual(valid.json()['status'], 'optimized')

    def test_select_endpoint_serves_ui_proposals_without_retrieval(self):
        data = encode(noise(300, 200))
        with TestClient(create_app(Settings(), object(), None)) as client:
            response = client.post('/select', files={'image': ('a.png', data, 'image/png')})
            self.assertEqual(response.status_code, 200)
            body = response.json()
            for key in ('boxes', 'candidates', 'reason'):
                self.assertIn(key, body)
            for candidate in body['candidates']:
                for key in ('x', 'y', 'width', 'height'):
                    self.assertGreaterEqual(candidate['box'][key], 0)
                    self.assertLessEqual(candidate['box'][key], 1)
            bad = client.post('/select', files={'image': ('a.txt', b'not-an-image', 'text/plain')})
            self.assertEqual(bad.status_code, 422)


if __name__ == '__main__':
    unittest.main()
