import unittest
from dataclasses import asdict
from io import BytesIO
from PIL import Image
import base64
from app.preprocessing.selection import (BoundingBox, Candidate, ForegroundSelector,
                                         MultiSourceSelector, SelectionPipeline,
                                         default_pipeline, propose, parse_selection)
from app.preprocessing.images import image_variants, prepare_upload
from app.preprocessing.pipeline import decode_image


class SelectionTests(unittest.TestCase):
    def test_processing_budget_preserves_pixels_without_reencoding(self):
        source = Image.new('RGB',(1000,800),(10,80,190))
        exif = source.getexif(); exif[270] = 'private'; exif[274] = 6
        stream = BytesIO(); source.save(stream,'JPEG',exif=exif)
        data = prepare_upload(stream.getvalue(),2000000,2000000,20)
        self.assertEqual(data['status'],'preserved_memory_budget')
        self.assertNotIn('catalog',data)
        original = Image.open(BytesIO(stream.getvalue()))
        preserved = Image.open(BytesIO(base64.b64decode(data['master'])))
        self.assertEqual(original.tobytes(),preserved.tobytes())
        self.assertEqual(preserved.size,(1000,800))
        self.assertEqual(dict(preserved.getexif()),{274:6})

    def test_pixel_guard_and_corrupt_preserved_input(self):
        source = Image.new('RGB',(100,80),'red'); stream=BytesIO(); source.save(stream,'JPEG')
        self.assertEqual(prepare_upload(stream.getvalue(),100000,100,256)['status'],'preserved_pixel_limit')
        with self.assertRaises(ValueError): prepare_upload(stream.getvalue()[:-20],100000,100,256)

    def test_png_preservation_removes_text_metadata(self):
        from PIL.PngImagePlugin import PngInfo
        metadata=PngInfo(); metadata.add_text('location','private')
        stream=BytesIO(); Image.new('RGB',(100,80)).save(stream,'PNG',pnginfo=metadata)
        prepared=prepare_upload(stream.getvalue(),100000,100,256)
        saved=Image.open(BytesIO(base64.b64decode(prepared['master'])))
        self.assertNotIn('location',saved.info)
    def test_invalid_coordinates(self):
        for values in ((0,0,0,1), (.9,0,.2,1), (float('nan'),0,1,1), (-1,0,1,1)):
            with self.assertRaises(ValueError):
                BoundingBox(*values)

    def test_contract_bounds_tolerance_and_tiny_rejection(self):
        BoundingBox(0, 0, 1, 1)
        BoundingBox(.9, .9, .10005, .10005)  # within TOLERANCE
        for values in ((.9, 0, .1002, 1), (0, .9, 1, .1002), (-.001, 0, 1, 1), (1.001, 0, .01, 1),
                       (0, 0, 1.001, 1), (0, 0, .01, 1.001), (0, 0, .005, 1), (0, 0, 1, .005)):
            with self.assertRaises(ValueError, msg=f'expected rejection for {values}'):
                BoundingBox(*values)

    def test_parse_selection_pairing_rules(self):
        none = (None, None, None, None)
        self.assertIsNone(parse_selection(none))
        self.assertIsNone(parse_selection(none, 'full'))
        self.assertIsNone(parse_selection(none, 'auto'))
        with self.assertRaises(ValueError):
            parse_selection(none, 'manual')
        with self.assertRaises(ValueError):
            parse_selection((0, 0, .5, .5), 'full')
        self.assertEqual(asdict(parse_selection((0, 0, .5, .5), 'auto')),
                         {'x': 0.0, 'y': 0.0, 'width': .5, 'height': .5})
        with self.assertRaises(ValueError):
            parse_selection((0, 0, .5, None))
        self.assertEqual(asdict(parse_selection((0, 0, .5, .5), 'manual')),
                         {'x': 0.0, 'y': 0.0, 'width': .5, 'height': .5})
        self.assertIsNotNone(parse_selection((0, 0, .5, .5)))

    def test_pixel_conversion_landscape_and_portrait(self):
        for size, expected in (((200, 100), (100, 50)), ((100, 200), (50, 100))):
            with self.subTest(size=size):
                self.assertEqual(BoundingBox(.1, .2, .5, .5).crop(Image.new('RGB', size)).size, expected)

    def test_pixel_conversion_floors_origin_and_ceils_far_edge(self):
        self.assertEqual(BoundingBox(0, 0, .333, .333).crop(Image.new('RGB', (100, 100))).size, (34, 34))

    def test_tiny_crop_never_yields_zero_size(self):
        with self.assertRaises(ValueError):
            BoundingBox(0, 0, .01, .01).crop(Image.new('RGB', (60, 60)))

    def test_fallback_and_crop(self):
        source = Image.new('RGB', (100, 200))
        self.assertEqual(BoundingBox(.1,.2,.5,.5).crop(source).size, (50,100))
        class Broken:
            def select(self, image): raise RuntimeError('unavailable')
        self.assertEqual(propose(source, Broken()), {'boxes': [], 'candidates': [], 'reason': 'foreground_failed'})

    def test_compressed_variants_keep_resolution_strip_exif(self):
        source = Image.new('RGB', (1800, 900), (20,90,180))
        exif = source.getexif(); exif[274] = 6; exif[270] = 'private comment'
        stream = BytesIO(); source.save(stream, 'JPEG', exif=exif)
        oriented = decode_image(stream.getvalue(), 2000000, 2000000)
        data = image_variants(oriented)
        for name, dimensions in [('master',(900,1800)), ('catalog',(800,1600)), ('thumbnail',(200,400))]:
            with Image.open(BytesIO(base64.b64decode(data[name]))) as image:
                self.assertEqual(image.size, dimensions)
                self.assertFalse(image.getexif())
                self.assertEqual(image.format, 'WEBP')


class FakeSelector:
    def __init__(self, candidates=(), error=None):
        self.candidates, self.error, self.calls = candidates, error, 0
    def select(self, image):
        self.calls += 1
        if self.error:
            raise self.error
        return list(self.candidates)


class FakeDetector:
    def __init__(self, returned=()):
        self.returned = returned
    def boxes(self, image):
        return list(self.returned)


class SelectionPipelineTests(unittest.TestCase):
    def test_candidate_metadata_source_and_coverage_score(self):
        candidates = ForegroundSelector(detector=FakeDetector([(10, 20, 60, 90)])).select(Image.new('RGB', (100, 100)))
        self.assertEqual(len(candidates), 1)
        self.assertEqual(candidates[0].source, 'grabcut_foreground')
        self.assertEqual(asdict(candidates[0].box), {'x': .1, 'y': .2, 'width': .5, 'height': .7})
        self.assertAlmostEqual(candidates[0].score, .35)
        self.assertNotIn('probability', asdict(candidates[0]))

    def test_propose_carries_metadata_and_truncates_to_five(self):
        candidates = [Candidate(BoundingBox(0, 0, .1, .1), 'unit') for _ in range(6)]
        proposal = propose(Image.new('RGB', (64, 64)), FakeSelector(candidates))
        self.assertEqual(proposal['reason'], 'foreground_proposal')
        self.assertEqual(len(proposal['boxes']), 5)
        self.assertEqual(len(proposal['candidates']), 5)
        self.assertEqual(proposal['candidates'][0]['source'], 'unit')

    def test_padding_is_configurable_and_validated(self):
        for padding in (.05, .10):
            ForegroundSelector(padding=padding)
        with self.assertRaises(ValueError):
            ForegroundSelector(padding=.3)
        with self.assertRaises(ValueError):
            SelectionPipeline(FakeSelector([]), fallback_padding=.3)

    def test_pipeline_prefers_primary_candidates(self):
        primary = FakeSelector([Candidate(BoundingBox(.1, .1, .4, .4), 'primary', .16)])
        fallback = FakeSelector([Candidate(BoundingBox(0, 0, 1, 1), 'fallback')])
        proposal = SelectionPipeline(primary, fallback).propose(Image.new('RGB', (64, 64)))
        self.assertEqual(proposal['reason'], 'foreground_proposal')
        self.assertEqual(proposal['candidates'][0]['source'], 'primary')
        self.assertEqual(fallback.calls, 0)

    def test_clean_uncertain_result_never_triggers_second_detector(self):
        fallback = FakeSelector([])
        proposal = SelectionPipeline(FakeSelector([]), fallback).propose(Image.new('RGB', (64, 64)))
        self.assertEqual(proposal['reason'], 'foreground_uncertain')
        self.assertEqual(fallback.calls, 0)

    def test_selector_failure_falls_back_to_foreground_preprocessing(self):
        fallback = FakeSelector([Candidate(BoundingBox(0, 0, .5, .5), 'foreground_fallback', .25)])
        proposal = SelectionPipeline(FakeSelector(error=RuntimeError('unavailable')), fallback).propose(Image.new('RGB', (64, 64)))
        self.assertEqual(proposal['reason'], 'foreground_fallback')
        self.assertEqual(proposal['candidates'][0]['source'], 'foreground_fallback')

    def test_pipeline_ends_in_full_image_when_both_stages_abstain(self):
        pipeline = SelectionPipeline(FakeSelector(error=RuntimeError('unavailable')), FakeSelector([]))
        self.assertEqual(pipeline.propose(Image.new('RGB', (64, 64))),
                         {'boxes': [], 'candidates': [], 'reason': 'full_image_fallback'})

    def test_pipeline_ends_in_full_image_when_fallback_also_fails(self):
        pipeline = SelectionPipeline(FakeSelector(error=RuntimeError('down')), FakeSelector(error=RuntimeError('down')))
        self.assertEqual(pipeline.propose(Image.new('RGB', (64, 64)))['reason'], 'full_image_fallback')

    def test_uniform_image_has_no_clear_foreground(self):
        proposal = propose(Image.new('RGB', (96, 96), 'red'), ForegroundSelector())
        self.assertEqual(proposal['reason'], 'foreground_uncertain')
        self.assertEqual(proposal['boxes'], [])

    def test_default_pipeline_shares_one_multi_source_construction(self):
        pipeline = default_pipeline()
        self.assertIsInstance(pipeline.selector, MultiSourceSelector)
        self.assertEqual([selector.source for selector in pipeline.selector.selectors],
                         ['grabcut_foreground', 'saliency', 'color_pop', 'contour'])
        # A featureless photo abstains everywhere: clean abstention, never
        # a fake box, and the shared factory stays fast on tiny inputs.
        proposal = pipeline.propose(Image.new('RGB', (96, 96), 'red'))
        self.assertEqual(proposal['reason'], 'foreground_uncertain')
        self.assertEqual(proposal['boxes'], [])

    def test_cluttered_image_proposals_stay_within_bounds(self):
        from PIL import ImageDraw
        image = Image.new('RGB', (300, 300), 'white')
        draw = ImageDraw.Draw(image)
        # Each component must exceed GrabCutForeground's 12% abstention floor.
        draw.rectangle((30, 30, 150, 150), fill='black')
        draw.rectangle((150, 150, 270, 270), fill='navy')
        draw.rectangle((10, 240, 30, 280), fill='gray')
        proposal = propose(image, ForegroundSelector(padding=.08))
        self.assertEqual(proposal['reason'], 'foreground_proposal')
        self.assertLessEqual(len(proposal['candidates']), 5)
        self.assertGreaterEqual(len(proposal['candidates']), 1)
        for candidate in proposal['candidates']:
            box = candidate['box']
            self.assertEqual(candidate['source'], 'grabcut_foreground')
            self.assertIsNotNone(candidate['score'])
            self.assertGreater(candidate['score'], 0)
            self.assertLessEqual(candidate['score'], 1)
            for value in (box['x'], box['y'], box['width'], box['height']):
                self.assertGreaterEqual(value, 0)
                self.assertLessEqual(value, 1)
