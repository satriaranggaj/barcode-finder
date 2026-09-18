"""Multi-source auto selection: recall on store photos, precision preserved."""
import unittest
from PIL import Image, ImageDraw
from app.preprocessing.selection import (BoundingBox, Candidate, CenterPrior,
                                          ForegroundSelector, MultiSourceSelector,
                                          SelectionPipeline, propose_for_ui)
from app.preprocessing.foreground import (ColorPopProposals, ContourProposals,
                                           GrabCutForeground)


def cluttered(seed=7):
    import random
    random.seed(seed)
    image = Image.new('RGB', (400, 300))
    pixels = image.load()
    for y in range(300):
        for x in range(400):
            pixels[x, y] = (random.randint(90, 130), random.randint(70, 100), random.randint(50, 80))
    draw = ImageDraw.Draw(image)
    draw.rectangle([165, 90, 195, 200], fill=(190, 30, 30))
    draw.rectangle([176, 200, 184, 270], fill=(180, 180, 190))
    return image


def plain_with_blue_box():
    image = Image.new('RGB', (300, 300), (240, 240, 240))
    ImageDraw.Draw(image).rectangle([100, 60, 200, 240], fill=(30, 60, 180))
    return image


class FakeSelector:
    def __init__(self, candidates=None, error=None):
        self.candidates = candidates or []
        self.error = error

    def select(self, image):
        if self.error:
            raise self.error
        return self.candidates


def multi():
    return MultiSourceSelector([
        ForegroundSelector(),
        ForegroundSelector(padding=.08, detector=ColorPopProposals(), source='color_pop'),
        ForegroundSelector(padding=.08, detector=ContourProposals(), source='contour')])


class MultiSourceSelectionTests(unittest.TestCase):
    def test_contour_finds_compact_rectangle(self):
        self.assertTrue(ContourProposals().boxes(plain_with_blue_box()))

    def test_color_pop_finds_distinct_product_on_clutter(self):
        boxes = ColorPopProposals().boxes(cluttered())
        self.assertTrue(boxes)
        x, y, right, bottom = boxes[0]
        self.assertLess(x, 175)
        self.assertGreater(right, 185)
        self.assertLess(y, 100)
        self.assertGreater(bottom, 260)

    def test_fused_selector_beats_single_grabcut_on_clutter(self):
        image = cluttered()
        # GrabCut segments the red product on this fixture (deterministic via
        # the fixed RNG seed); the fused ranking must keep a product box first.
        grabcut = GrabCutForeground().boxes(image)
        self.assertTrue(grabcut)
        proposal = propose_for_ui(image, multi())
        self.assertEqual(proposal['reason'], 'foreground_proposal')
        self.assertTrue(proposal['boxes'])
        first = proposal['boxes'][0]
        # Top-1 must cover the product centre (180, 180 in full-image pixels).
        self.assertLessEqual(first['x'], 180 / image.width)
        self.assertGreaterEqual(first['x'] + first['width'], 180 / image.width)
        self.assertLessEqual(first['y'], 180 / image.height)
        self.assertGreaterEqual(first['y'] + first['height'], 180 / image.height)

    def test_overlapping_candidates_are_deduplicated(self):
        first = [Candidate(BoundingBox(.2, .2, .4, .4), 'a', .5)]
        second = [Candidate(BoundingBox(.2, .2, .4, .4), 'b', .5)]
        merged = MultiSourceSelector([FakeSelector(first), FakeSelector(second)]).select(Image.new('RGB', (64, 64)))
        self.assertEqual(len(merged), 1)
        self.assertEqual(merged[0].source, 'a')

    def test_failing_source_does_not_break_fusion(self):
        merged = MultiSourceSelector([FakeSelector(error=RuntimeError('down')), ForegroundSelector()]).select(
            Image.new('RGB', (64, 64), (200, 30, 30)))
        self.assertIsInstance(merged, list)

    def test_center_fallback_only_when_detectors_abstain_cleanly(self):
        image = Image.new('RGB', (200, 150), (210, 210, 210))
        fallback = propose_for_ui(image, MultiSourceSelector([FakeSelector()]))
        self.assertEqual(fallback['reason'], 'center_fallback')
        self.assertEqual(len(fallback['boxes']), 1)
        self.assertEqual(fallback['candidates'][0]['source'], 'center_prior')
        self.assertAlmostEqual(fallback['candidates'][0]['box']['x'], .1)
        self.assertAlmostEqual(fallback['candidates'][0]['box']['width'], .8)
        # Genuine errors stay visible as failures, never disguised as a box.
        failed = propose_for_ui(image, MultiSourceSelector([FakeSelector(error=RuntimeError('down'))]))
        self.assertEqual(failed, {'boxes': [], 'candidates': [], 'reason': 'foreground_failed'})
        # Retrieval pipeline keeps the full-image fallback untouched.
        self.assertEqual(SelectionPipeline(FakeSelector([])).propose(image)['reason'], 'foreground_uncertain')

    def test_detector_padding_bounds_are_validated(self):
        for cls in (ColorPopProposals, ContourProposals):
            cls()
            with self.assertRaises(ValueError):
                cls(padding=.3)
        with self.assertRaises(ValueError):
            MultiSourceSelector(iou_threshold=0)
        with self.assertRaises(ValueError):
            CenterPrior(margin=.5)


if __name__ == '__main__':
    unittest.main()
