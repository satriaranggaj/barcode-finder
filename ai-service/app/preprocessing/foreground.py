"""Replaceable foreground detector. GrabCut is a heuristic, not a product detector."""
from typing import Protocol
import numpy as np
from PIL import Image


class ForegroundExtractor(Protocol):
    def box(self, image: Image.Image) -> tuple[int, int, int, int] | None: ...


def _pad_box(x, y, bw, bh, w, h, padding):
    """Expand a thumbnail-space box by padding, clamped to the image."""
    px, py = max(4, int(bw * padding)), max(4, int(bh * padding))
    return (max(0, x - px), max(0, y - py), min(w, x + bw + px), min(h, y + bh + py))


def _to_full_image(box, thumb_w, thumb_h, image):
    x, y, right, bottom = box
    return (int(x * image.width / thumb_w), int(y * image.height / thumb_h),
            int(right * image.width / thumb_w), int(bottom * image.height / thumb_h))


def _iou(a, b):
    ax, ay, ar, ab = a
    bx, by, br, bb = b
    width, height = min(ar, br) - max(ax, bx), min(ab, bb) - max(ay, by)
    if width <= 0 or height <= 0:
        return 0.0
    inter = width * height
    union = (ar - ax) * (ab - ay) + (br - bx) * (bb - by) - inter
    return inter / union if union > 0 else 0.0


def _containment(a, b):
    """Fraction of the smaller box covered by the intersection (1.0 = nested)."""
    ax, ay, ar, ab = a
    bx, by, br, bb = b
    width, height = min(ar, br) - max(ax, bx), min(ab, bb) - max(ay, by)
    if width <= 0 or height <= 0:
        return 0.0
    smaller = min((ar - ax) * (ab - ay), (br - bx) * (bb - by))
    return (width * height) / smaller if smaller > 0 else 0.0


def _dedup_boxes(scored, limit=5, iou_threshold=.5, containment_threshold=.9,
                 max_merge_ratio=4.0):
    """Keep best-first boxes; drop overlaps and nested fragments.

    `scored` is [(score, box)] sorted best-first. A box overlapping a kept box
    (IoU) is a duplicate view of the same object. A box 90%+ contained in a
    kept box (or vice versa) at a comparable scale is a fragment of it — the
    larger superset is the better object hypothesis. A superset several times
    larger is a different hypothesis (e.g. a near-frame box), not the same
    object, so it never absorbs the smaller box.
    """
    kept = []
    for _score, box in scored:
        duplicate = False
        (bx, by, br, bb) = box
        area = (br - bx) * (bb - by)
        for position, (_kept_score, kept_box) in enumerate(kept):
            if _iou(box, kept_box) > iou_threshold:
                duplicate = True
                break
            (kx, ky, kr, kb) = kept_box
            kept_area = (kr - kx) * (kb - ky)
            if kept_area <= 0 or area <= 0:
                continue
            if _containment(box, kept_box) > containment_threshold and \
                    max(area, kept_area) / min(area, kept_area) < max_merge_ratio:
                if area > kept_area:
                    kept[position] = (_score, box)
                duplicate = True
                break
        if not duplicate:
            kept.append((_score, box))
    return kept[:limit]


class GrabCutForeground:
    """GrabCut seeded with an explicit border ring and a central foreground prior.

    A plain rectangle seed tells GrabCut that everything inside a slightly inset
    rectangle is probable foreground. On the common staff photo where the product
    fills most of the frame that returns the whole frame (or a wide band), so the
    object is never isolated. Seeding a definite-background border ring plus a
    central probable-foreground ellipse keeps the object separable while still
    allowing boxes that touch the image edge. Cost stays bounded at 384 px.
    """
    def __init__(self, padding: float = .15, min_fraction: float = .02,
                 max_fraction: float = .92, iterations: int = 2):
        if not 0 <= padding <= .25:
            raise ValueError('Selection padding must be 0..0.25')
        if not 0 < min_fraction < max_fraction <= 1:
            raise ValueError('Foreground area window must satisfy 0 < min < max <= 1')
        self.padding = padding
        self.min_fraction = min_fraction
        self.max_fraction = max_fraction
        self.iterations = iterations

    def box(self, image: Image.Image) -> tuple[int, int, int, int] | None:
        boxes = self.boxes(image)
        return boxes[0] if boxes else None

    def boxes(self, image: Image.Image) -> list[tuple[int, int, int, int]]:
        import cv2
        small = image.convert('RGB').copy()
        small.thumbnail((384, 384))
        data = np.asarray(small)
        h, w = data.shape[:2]
        if min(h, w) < 32:
            return []
        # A featureless photo has no foreground to isolate; abstain instead of
        # returning the seed ellipse as a fake detection.
        if float(cv2.cvtColor(data, cv2.COLOR_RGB2GRAY).std()) < 6:
            return []
        ring = max(2, int(min(h, w) * .02))
        mask = np.full((h, w), cv2.GC_PR_BGD, np.uint8)
        mask[:ring, :] = cv2.GC_BGD
        mask[-ring:, :] = cv2.GC_BGD
        mask[:, :ring] = cv2.GC_BGD
        mask[:, -ring:] = cv2.GC_BGD
        cv2.ellipse(mask, (w // 2, h // 2), (max(4, int(w * .3)), max(4, int(h * .3))),
                    0, 0, 360, cv2.GC_PR_FGD, -1)
        # Fixed RNG seed: the same staff photo must yield the same proposal on
        # every request, so the UI frame and search stay stable and comparable.
        cv2.setRNGSeed(0)
        cv2.grabCut(data, mask, None, np.zeros((1, 65), np.float64), np.zeros((1, 65), np.float64),
                    self.iterations, cv2.GC_INIT_WITH_MASK)
        fg = np.isin(mask, (cv2.GC_FGD, cv2.GC_PR_FGD)).astype(np.uint8)
        fg = cv2.morphologyEx(fg, cv2.MORPH_CLOSE, np.ones((5, 5), np.uint8))
        count, _, stats, _ = cv2.connectedComponentsWithStats(fg)
        if count <= 1:
            return []
        scored = []
        for component in stats[1:]:
            x, y, bw, bh, area = map(int, component)
            if not self.min_fraction <= area / (h * w) <= self.max_fraction:
                continue
            # A component spanning the whole frame is not a usable crop.
            if bw * bh >= self.max_fraction * w * h:
                continue
            scored.append((_pad_box(x, y, bw, bh, w, h, self.padding), area))
        # Best-first by shared heuristic quality; every box stays user-editable.
        from .saliency import box_quality
        scored.sort(key=lambda row: (box_quality(row[0], w, h), row[1]), reverse=True)
        return [_to_full_image(box, w, h, image) for box, _ in scored[:5]]


class SaliencyProposals:
    """Compact regions that stand out from their surroundings (spectral residual).

    A handheld product on a shop shelf or in a hand is the salient content of a
    staff photo, which makes a classical saliency map a good proposal source in
    the clutter where GrabCut abstains. Cost is one FFT pair at 384 px — no model
    and no extra dependency. Several thresholds are swept because no single
    cut-off generalizes across lighting; boxes are returned best-first by the
    shared heuristic quality (saliency mass, size plausibility, centrality).
    """
    def __init__(self, padding: float = .08, min_fraction: float = .015,
                 max_fraction: float = .92, percentiles: tuple = (85.0, 92.0, 97.0)):
        if not 0 <= padding <= .25:
            raise ValueError('Selection padding must be 0..0.25')
        if not 0 < min_fraction < max_fraction <= 1:
            raise ValueError('Saliency area window must satisfy 0 < min < max <= 1')
        self.padding = padding
        self.min_fraction = min_fraction
        self.max_fraction = max_fraction
        self.percentiles = percentiles

    def boxes(self, image: Image.Image) -> list[tuple[int, int, int, int]]:
        import cv2
        from .saliency import box_quality, spectral_residual
        small = image.convert('RGB').copy()
        small.thumbnail((384, 384))
        data = np.asarray(small)
        h, w = data.shape[:2]
        if min(h, w) < 32:
            return []
        saliency = spectral_residual(cv2.cvtColor(data, cv2.COLOR_RGB2GRAY).astype(np.float32))
        if float(saliency.max()) <= 0:
            return []
        kernel = np.ones((7, 7), np.uint8)
        scored = []
        for percentile in self.percentiles:
            # Pure percentile cut-off: the sweep across percentiles already spans
            # strict/loose regimes. A mean+std floor collapses every percentile
            # to one value on peaky maps and kills recall, so it is not applied.
            threshold = float(np.percentile(saliency, percentile))
            binary = (saliency >= threshold).astype(np.uint8) * 255
            closed = cv2.morphologyEx(binary, cv2.MORPH_CLOSE, kernel, iterations=2)
            closed = cv2.morphologyEx(closed, cv2.MORPH_OPEN, np.ones((3, 3), np.uint8))
            count, _, stats, _ = cv2.connectedComponentsWithStats(closed)
            if count <= 1:
                continue
            for component in stats[1:]:
                x, y, bw, bh, _size = map(int, component)
                if not self.min_fraction <= bw * bh / (h * w) <= self.max_fraction:
                    continue
                if min(bw, bh) < 10:
                    continue
                # Bands spanning the full width are shelf/background geometry.
                if x <= 1 and x + bw >= w - 1:
                    continue
                box = _pad_box(x, y, bw, bh, w, h, self.padding)
                scored.append((box_quality(box, w, h, saliency), box))
        scored.sort(key=lambda row: row[0], reverse=True)
        return [_to_full_image(box, w, h, image) for _quality, box in _dedup_boxes(scored)]


class ColorPopProposals:
    """Compact regions whose color stands out from the image median.

    Handheld products usually differ in color from shelves and hands; the
    median approximates the background on store photos. Several distance
    percentiles are tried because no single threshold generalizes, and
    background bands are rejected like in ContourProposals. Pure numpy plus
    connected components — no model, no extra dependency.

    Ordering used to be pure color distance, which let a single saturated
    background patch outrank the product; scores now also carry size
    plausibility, centrality and aspect so a product-sized region wins.
    """
    def __init__(self, padding: float = .08, min_fraction: float = .015,
                 max_fraction: float = .92, percentiles: tuple = (93, 96, 98.5)):
        if not 0 <= padding <= .25:
            raise ValueError('Selection padding must be 0..0.25')
        if not 0 < min_fraction < max_fraction <= 1:
            raise ValueError('Color-pop area window must satisfy 0 < min < max <= 1')
        self.padding = padding
        self.min_fraction = min_fraction
        self.max_fraction = max_fraction
        self.percentiles = percentiles

    def boxes(self, image: Image.Image) -> list[tuple[int, int, int, int]]:
        import cv2
        from .saliency import box_quality
        small = image.convert('RGB').copy()
        small.thumbnail((384, 384))
        data = np.asarray(small).astype(np.float32)
        h, w = data.shape[:2]
        if min(h, w) < 32:
            return []
        median = np.median(data.reshape(-1, 3), axis=0)
        distance = np.linalg.norm(data - median, axis=2)
        if float(distance.max()) < 30:
            return []
        area = h * w
        scored = []
        kernel = np.ones((7, 7), np.uint8)
        for percentile in self.percentiles:
            binary = (distance > float(np.percentile(distance, percentile))).astype(np.uint8) * 255
            closed = cv2.morphologyEx(binary, cv2.MORPH_CLOSE, kernel)
            count, _, stats, _ = cv2.connectedComponentsWithStats(closed)
            if count <= 1:
                continue
            for component in stats[1:]:
                x, y, bw, bh, _size = map(int, component)
                if not self.min_fraction <= bw * bh / area <= self.max_fraction:
                    continue
                if bw < 8 or bh < 8:
                    continue
                if x <= 1 and x + bw >= w - 1:
                    continue
                region = distance[y:y + bh, x:x + bw]
                # Geometry leads, colour breaks ties: a strong colour deviation
                # must not let a background fragment outrank a product-sized box
                # that also differs from the median.
                contrast = min(1.0, (float(region.mean()) if region.size else 0.0) / 128.0)
                box = _pad_box(x, y, bw, bh, w, h, self.padding)
                scored.append((box_quality(box, w, h) * (.65 + .35 * contrast), box))
        scored.sort(key=lambda row: row[0], reverse=True)
        return [_to_full_image(box, w, h, image) for _quality, box in _dedup_boxes(scored)]


class ContourProposals:
    """Edge-contour boxes for compact products GrabCut deems too small.

    Blurred Canny edges closed morphologically, then external contours become
    boxes. Accepts smaller areas (down to ~3%) and near-border objects that
    GrabCut rejects, at the cost of occasional shelf fragments — acceptable
    because every proposal is user-editable and ranked after GrabCut.
    """
    def __init__(self, padding: float = .08, min_fraction: float = .02,
                 max_fraction: float = .92):
        if not 0 <= padding <= .25:
            raise ValueError('Selection padding must be 0..0.25')
        if not 0 < min_fraction < max_fraction <= 1:
            raise ValueError('Contour area window must satisfy 0 < min < max <= 1')
        self.padding = padding
        self.min_fraction = min_fraction
        self.max_fraction = max_fraction

    def boxes(self, image: Image.Image) -> list[tuple[int, int, int, int]]:
        import cv2
        from .saliency import box_quality
        small = image.convert('L').copy()
        small.thumbnail((384, 384))
        data = np.asarray(small)
        h, w = data.shape[:2]
        if min(h, w) < 32:
            return []
        blurred = cv2.GaussianBlur(data, (5, 5), 0)
        edges = cv2.Canny(blurred, 50, 150)
        closed = cv2.dilate(edges, np.ones((7, 7), np.uint8), iterations=2)
        contours, _ = cv2.findContours(closed, cv2.RETR_EXTERNAL, cv2.CHAIN_APPROX_SIMPLE)
        area = h * w
        scored = []
        for contour in contours:
            x, y, bw, bh = map(int, cv2.boundingRect(contour))
            if not self.min_fraction <= bw * bh / area <= self.max_fraction:
                continue
            if bw < 10 or bh < 10:
                continue
            # Bands spanning the full width are shelf/background geometry.
            if x <= 2 and x + bw >= w - 2:
                continue
            aspect = bw / max(1, bh)
            if not .12 <= aspect <= 8:
                continue
            # Shape quality (size plausibility, centrality, aspect) ranks boxes.
            box = _pad_box(x, y, bw, bh, w, h, self.padding)
            scored.append((box_quality(box, w, h), box))
        scored.sort(key=lambda row: row[0], reverse=True)
        return [_to_full_image(box, w, h, image) for _quality, box in _dedup_boxes(scored)]
