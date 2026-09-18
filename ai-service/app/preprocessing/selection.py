"""Normalized, oriented-image coordinates shared by query and reference ingestion.

Coordinates are fractions of the orientation-normalized image (EXIF orientation
already applied by decode_image), so x/y/width/height are independent of raw
pixel dimensions. The same convention is mirrored by App\\Services\\CropCoordinates
in Laravel; keep constants in sync when changing them.

Object selection is modular: SelectionPipeline runs any ObjectSelector and falls
back through foreground preprocessing to the full image, so search never fails
on selection. Selectors return Candidate metadata (coordinates, source method,
coverage heuristic score) — the score is a heuristic, never a probability.
"""
from dataclasses import dataclass, asdict, replace
import math
from typing import Protocol
from PIL import Image
from .foreground import GrabCutForeground

# Sum tolerance: absorbs 4-decimal coordinate rounding plus float error.
TOLERANCE = 1e-4
# Minimum normalized crop side; smaller crops are rejected, never silently accepted.
MIN_EXTENT = .01
# Crop must yield at least this many pixels per side after pixel rounding.
MIN_PIXEL_SIDE = 2


@dataclass(frozen=True)
class BoundingBox:
    x: float
    y: float
    width: float
    height: float

    def __post_init__(self):
        if not all(math.isfinite(v) for v in asdict(self).values()):
            raise ValueError('Crop coordinates must be finite')
        if not 0 <= self.x <= 1 or not 0 <= self.y <= 1:
            raise ValueError('Crop x and y must be within 0..1')
        if not MIN_EXTENT <= self.width <= 1 or not MIN_EXTENT <= self.height <= 1:
            raise ValueError(f'Crop width and height must be within {MIN_EXTENT}..1')
        if self.x + self.width > 1 + TOLERANCE or self.y + self.height > 1 + TOLERANCE:
            raise ValueError('Crop extends beyond the image')

    def crop(self, image: Image.Image) -> Image.Image:
        # Floor/ceil containment: the pixel box always covers the normalized box.
        left, top = int(self.x * image.width), int(self.y * image.height)
        right = min(image.width, math.ceil((self.x+self.width)*image.width))
        bottom = min(image.height, math.ceil((self.y+self.height)*image.height))
        if min(right-left, bottom-top) < MIN_PIXEL_SIDE:
            raise ValueError('Crop is too small')
        return image.crop((left, top, right, bottom))


@dataclass(frozen=True)
class Candidate:
    """One proposed object region plus minimal provenance metadata.

    score is a coverage heuristic (fraction of the image the box occupies) as
    reported by the originating selector. rank is the shared cross-selector
    ordering heuristic (saliency mass, size plausibility, centrality, aspect)
    used to sort candidates best-first. Neither value is a detection
    probability and both are safe to ignore for user-edited boxes.
    """
    box: BoundingBox
    source: str
    score: float | None = None
    rank: float | None = None


def parse_selection(coordinates, selection_mode=None):
    """Validate crop coordinates plus optional selection_mode pairing.

    'manual' requires all four coordinates; 'full' forbids them. 'auto' may
    include the proposal already displayed by the client, avoiding a second
    detection with a different box. All supplied coordinates are validated.
    """
    present = any(value is not None for value in coordinates)
    if selection_mode == 'manual' and not all(value is not None for value in coordinates):
        raise ValueError('Crop coordinates are required for selection_mode=manual')
    if selection_mode == 'full' and present:
        raise ValueError(f'Crop coordinates are not allowed for selection_mode={selection_mode}')
    if present and not all(value is not None for value in coordinates):
        raise ValueError('All four crop coordinates are required')
    return BoundingBox(*coordinates) if present else None


class ObjectSelector(Protocol):
    def select(self, image: Image.Image) -> list[Candidate]: ...


class ForegroundSelector:
    """GrabCut-backed foreground selector. The detector is injectable so the
    underlying heuristic can be swapped without touching the search pipeline."""
    def __init__(self, padding: float = .08, detector=None, source: str = 'grabcut_foreground'):
        if not 0 <= padding <= .25:
            raise ValueError('Selection padding must be 0..0.25')
        self.detector = detector or GrabCutForeground(padding)
        self.source = source

    def select(self, image: Image.Image) -> list[Candidate]:
        candidates = []
        for x, y, right, bottom in self.detector.boxes(image):
            box = BoundingBox(x/image.width, y/image.height,
                              (right-x)/image.width, (bottom-y)/image.height)
            candidates.append(Candidate(box, self.source, box.width*box.height))
        return candidates


def _iou(a: BoundingBox, b: BoundingBox) -> float:
    width = min(a.x + a.width, b.x + b.width) - max(a.x, b.x)
    height = min(a.y + a.height, b.y + b.height) - max(a.y, b.y)
    if width <= 0 or height <= 0:
        return 0.0
    inter = width * height
    union = a.width * a.height + b.width * b.height - inter
    return inter / union if union > 0 else 0.0


class MultiSourceSelector:
    """Union of complementary detectors, deduplicated and ranked.

    GrabCut is precise on clean shots but abstains on clutter; saliency,
    color-pop and contours catch handheld/small products it misses. All
    candidates are collected first, then ordered best-first by geometry
    quality times source priority in `rank`, where near-duplicates (IoU or
    nesting) resolve in favour of the higher-ranked box — so a product-sized
    region is not outranked by a small saturated background patch, and
    fragments of one object do not crowd the candidate list. Every candidate
    stays user-editable, so recall beats precision here.
    """
    # Source priority for the fused ordering: GrabCut boxes are segmentation
    # evidence, colour/saliency/contour boxes are proposal evidence. Geometry
    # quality (size plausibility, centrality, aspect) always leads; the source
    # weight only breaks near-ties so a precise GrabCut box is not demoted by
    # a coincidental saliency peak elsewhere in the frame.
    SOURCE_PRIORITY = {
        'grabcut_foreground': 1.0,
        'foreground_fallback': .9,
        'color_pop': .85,
        'saliency': .7,
        'contour': .6,
    }
    # Independent corroboration: a box confirmed by other sources is far more
    # likely to be the product than a same-geometry box nobody else found.
    CORROBORATION_IOU = .5
    CORROBORATION_BONUS = .15
    CORROBORATION_CAP = 2
    # Candidates covering this much of the frame (or more) isolate nothing;
    # the pipeline falls back to the full image instead of cropping noise.
    MAX_COVERAGE = .75
    def __init__(self, selectors=None, iou_threshold: float = .65, limit: int = 5):
        from .foreground import ContourProposals, ColorPopProposals, SaliencyProposals
        self.selectors = selectors if selectors is not None else [
            ForegroundSelector(),
            ForegroundSelector(padding=.08, detector=SaliencyProposals(), source='saliency'),
            ForegroundSelector(padding=.08, detector=ColorPopProposals(), source='color_pop'),
            ForegroundSelector(padding=.08, detector=ContourProposals(), source='contour'),
        ]
        if not 0 < iou_threshold <= 1:
            raise ValueError('IoU threshold must be 0..1')
        self.iou_threshold = iou_threshold
        self.limit = limit

    def select(self, image: Image.Image) -> list[Candidate]:
        merged: list[Candidate] = []
        failures = 0
        for selector in self.selectors:
            try:
                candidates = selector.select(image)
            except Exception:
                failures += 1
                continue
            for candidate in candidates:
                try:
                    BoundingBox(**asdict(candidate.box))
                except ValueError:
                    continue
                merged.append(candidate)
        if not merged and failures == len(self.selectors) and self.selectors:
            # Every source errored: surface the outage instead of mimicking a
            # clean abstention, so callers keep their failure messaging.
            raise RuntimeError('All selection sources failed')
        return self.rank(image, merge_attached_parts(merged))

    def rank(self, image: Image.Image, candidates: list[Candidate]) -> list[Candidate]:
        """Score, deduplicate and truncate candidates best-first.

        Geometry (size plausibility, centrality, aspect) leads on purpose: the
        shared saliency map is peaky on store photos, and ranking by saliency
        mass demonstrably promotes boxes over background glare above precise
        GrabCut boxes. The map is therefore not consulted here; each detector
        already used its own evidence to propose. Near-full-frame boxes are
        dropped (they isolate nothing; the pipeline falls back to the full
        image), and near-duplicates resolve in ranked order so the best box
        survives instead of the earliest source. Ties keep the detector
        priority order, which makes the result deterministic. The value stored
        in `rank` is a heuristic ordering aid, never a probability.
        """
        if not candidates:
            return candidates
        small = image.convert('RGB').copy()
        small.thumbnail((384, 384))
        width, height = small.size
        from .saliency import box_quality
        scored = []
        for position, candidate in enumerate(candidates):
            box = candidate.box
            if box.width * box.height >= self.MAX_COVERAGE:
                continue
            pixel = (int(box.x * width), int(box.y * height),
                     max(1, int(box.width * width)), max(1, int(box.height * height)))
            quality = box_quality(pixel, width, height, None)
            quality *= self.SOURCE_PRIORITY.get(candidate.source, .5)
            scored.append((quality, position, candidate))
        scored.sort(key=lambda row: (-row[0], row[1]))
        # Independent corroboration: boxes confirmed by other sources outrank
        # same-geometry boxes nobody else found. Votes are counted before
        # deduplication so the corroborating duplicates still count, then the
        # dedup below keeps only the best box of each cluster.
        for index, (_quality, _position, candidate) in enumerate(scored):
            corroborators = {other.source for (_oquality, _oposition, other) in scored
                             if other is not candidate and other.source != candidate.source
                             and _iou(candidate.box, other.box) >= self.CORROBORATION_IOU}
            bonus = 1 + self.CORROBORATION_BONUS * min(self.CORROBORATION_CAP, len(corroborators))
            scored[index] = (_quality * bonus, _position, candidate)
        scored.sort(key=lambda row: (-row[0], row[1]))
        kept: list[Candidate] = []
        for quality, _position, candidate in scored:
            duplicate = False
            for other in kept:
                if _iou(candidate.box, other.box) >= self.iou_threshold:
                    duplicate = True
                    break
                smaller = min(candidate.box.width * candidate.box.height,
                              other.box.width * other.box.height)
                if smaller <= 0:
                    continue
                inter_width = (min(candidate.box.x + candidate.box.width, other.box.x + other.box.width)
                               - max(candidate.box.x, other.box.x))
                inter_height = (min(candidate.box.y + candidate.box.height, other.box.y + other.box.height)
                                - max(candidate.box.y, other.box.y))
                if inter_width > 0 and inter_height > 0 and inter_width * inter_height / smaller > .9:
                    duplicate = True
                    break
            if not duplicate:
                kept.append(replace(candidate, rank=round(quality, 6)))
            if len(kept) >= self.limit:
                break
        return kept


def default_pipeline(padding: float = .08, min_fraction: float = .02,
                     max_fraction: float = .92) -> 'SelectionPipeline':
    """One shared auto-selection construction for UI, serving and indexing.

    The /select UI, the /search serving path and the offline index build must
    propose the same boxes for the same photo; otherwise the UI preview, the
    query crop and the stored reference crop silently diverge. Every caller
    that needs auto-selection uses this factory instead of hand-rolling its
    own selector list.
    """
    from .foreground import ColorPopProposals, ContourProposals, SaliencyProposals
    area = {'min_fraction': min_fraction, 'max_fraction': max_fraction}
    return SelectionPipeline(MultiSourceSelector([
        ForegroundSelector(padding, detector=GrabCutForeground(padding, **area)),
        ForegroundSelector(padding, detector=SaliencyProposals(padding, **area),
                           source='saliency'),
        ForegroundSelector(padding, detector=ColorPopProposals(padding, **area),
                           source='color_pop'),
        ForegroundSelector(padding, detector=ContourProposals(padding, **area),
                           source='contour'),
    ]))


def _overlap_1d(a0, a1, b0, b1) -> float:
    return max(0.0, min(a1, b1) - max(a0, b0))


def merge_attached_parts(candidates: list[Candidate]) -> list[Candidate]:
    """Merge thin protrusions into the body they attach to.

    Tools segment by material: a yellow/black handle plus a silver shaft come
    out as two boxes (GrabCut splits them, colour-pop keeps them apart), and
    neither alone is the product. When a thin part sits flush against a wider
    body, fully inside the body's span — shaft into handle, pole into product —
    the union is the better object hypothesis. Strict on purpose: edges must
    nearly touch without overlapping (crossing boxes are separate objects, not
    parts), the part must sit inside the body span, and each box merges at
    most once so unions can never snowball across the frame.
    """
    used = set()
    merged: list[Candidate] = []
    for i, candidate in enumerate(candidates):
        if i in used:
            continue
        for j in range(i + 1, len(candidates)):
            if j in used:
                continue
            union = _try_attach(candidate, candidates[j])
            if union is None:
                continue
            used.add(j)
            candidate = union
            break
        merged.append(candidate)
    return merged


def _try_attach(a: Candidate, b: Candidate) -> Candidate | None:
    ax, ay, aw, ah = a.box.x, a.box.y, a.box.width, a.box.height
    bx, by, bw, bh = b.box.x, b.box.y, b.box.width, b.box.height
    if min(aw, ah) <= 0 or min(bw, bh) <= 0:
        return None
    union_x, union_y = min(ax, bx), min(ay, by)
    union_w = max(ax + aw, bx + bw) - union_x
    union_h = max(ay + ah, by + bh) - union_y
    if union_w * union_h >= .6:
        return None
    area_ratio = min(aw * ah, bw * bh) / max(aw * ah, bw * bh)
    if area_ratio >= .5:
        return None
    # Vertical stack: part flush above/below the body, inside its span.
    if _flush(by, by + bh, ay, ay + ah) and \
            _overlap_1d(ax, ax + aw, bx, bx + bw) / min(aw, bw) >= .8 and \
            min(aw, bw) / max(aw, bw) < .6:
        return _merged_candidate(a, b, union_x, union_y, union_w, union_h)
    # Horizontal stack: mirror case.
    if _flush(bx, bx + bw, ax, ax + aw) and \
            _overlap_1d(ay, ay + ah, by, by + bh) / min(ah, bh) >= .8 and \
            min(ah, bh) / max(ah, bh) < .6:
        return _merged_candidate(a, b, union_x, union_y, union_w, union_h)
    return None


def _flush(p0: float, p1: float, q0: float, q1: float) -> bool:
    """True when interval ends nearly touch without real overlap."""
    gap = p0 - q1 if p0 >= q0 else q0 - p1
    overlap = min(p1, q1) - max(p0, q0)
    return -0.01 <= gap <= 0.02 and overlap <= 0.01


def _merged_candidate(a: Candidate, b: Candidate, x: float, y: float,
                      width: float, height: float) -> Candidate:
    body = a if a.box.width * a.box.height >= b.box.width * b.box.height else b
    try:
        box = BoundingBox(x, y, width, height)
    except ValueError:
        return body
    return replace(body, box=box,
                   score=max(a.score or 0, b.score or 0) or None)


class CenterPrior:
    """Editable center box used only as a UI starting point, never as an
    automatic search crop: the retrieval pipeline keeps its full-image
    fallback when detectors abstain."""
    def __init__(self, margin: float = .1):
        if not 0 <= margin < .45:
            raise ValueError('Center margin must be 0..0.45')
        self.margin = margin

    def select(self, image: Image.Image) -> list[Candidate]:
        side = 1 - 2 * self.margin
        return [Candidate(BoundingBox(self.margin, self.margin, side, side),
                          'center_prior', side * side)]


def propose(image: Image.Image, selector: ObjectSelector) -> dict:
    """Run one selector, never raising. 'boxes' keeps the legacy shape;
    'candidates' adds provenance metadata for each box."""
    try:
        candidates = selector.select(image)[:5]
        return {'boxes': [asdict(candidate.box) for candidate in candidates],
                'candidates': [asdict(candidate) for candidate in candidates],
                'reason': 'foreground_proposal' if candidates else 'foreground_uncertain'}
    except Exception:
        return {'boxes': [], 'candidates': [], 'reason': 'foreground_failed'}


def propose_for_ui(image: Image.Image, selector: ObjectSelector) -> dict:
    """Proposal for the interactive editor: like propose(), but when every
    detector abstains the user still gets an editable center box instead of
    an empty canvas. The retrieval pipeline is untouched and keeps falling
    back to the full image."""
    proposal = propose(image, selector)
    if proposal['boxes'] or proposal['reason'] == 'foreground_failed':
        return proposal
    try:
        center = CenterPrior().select(image)[0]
        return {'boxes': [asdict(center.box)], 'candidates': [asdict(center)],
                'reason': 'center_fallback'}
    except Exception:
        return proposal


class SelectionPipeline:
    """Fallback chain: primary selector -> foreground preprocessing -> full image.

    A clean 'no foreground' answer from the primary selector never triggers a
    second detector with different padding; only an actual selector failure
    does. When every stage abstains the pipeline reports 'full_image_fallback'
    and search proceeds with the full image — selection failure can never fail
    a search.
    """
    def __init__(self, selector: ObjectSelector | None = None,
                 fallback: ObjectSelector | None = None, fallback_padding: float = .15):
        self.selector = selector or ForegroundSelector()
        self.fallback = fallback or ForegroundSelector(fallback_padding, source='foreground_fallback')

    def propose(self, image: Image.Image) -> dict:
        proposal = propose(image, self.selector)
        if proposal['boxes'] or proposal['reason'] != 'foreground_failed':
            return proposal
        fallback = propose(image, self.fallback)
        if fallback['boxes']:
            fallback['reason'] = 'foreground_fallback'
            return fallback
        return {'boxes': [], 'candidates': [], 'reason': 'full_image_fallback'}
