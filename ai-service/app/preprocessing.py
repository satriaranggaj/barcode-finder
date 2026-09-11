"""Bounded, deterministic object preprocessing shared by references and queries.

Algorithm constants are part of the version. Change VERSION when changing them.
GrabCut is class-agnostic: it cannot reliably distinguish a hand from a product.
"""
from dataclasses import dataclass
from time import perf_counter
import io
import os
import warnings

import numpy as np
from PIL import Image, ImageOps, UnidentifiedImageError

try:
    import cv2
except ImportError:
    cv2 = None

VERSION = "object-v1"
MAX_BYTES = 20 * 1024 * 1024
MAX_PIXELS = 80_000_000
WORK_SIZE = 384


@dataclass
class Prepared:
    image: Image.Image
    mask: np.ndarray | None
    metadata: dict


def decode(contents: bytes, legacy=False):
    started = perf_counter()
    if not contents or len(contents) > MAX_BYTES:
        raise ValueError("image_size_limit")
    try:
        with warnings.catch_warnings():
            warnings.simplefilter("error", Image.DecompressionBombWarning)
            with Image.open(io.BytesIO(contents)) as probe:
                if probe.format not in ("JPEG", "PNG", "WEBP"):
                    raise ValueError("unsupported_image")
                if probe.width * probe.height > MAX_PIXELS or getattr(probe, "n_frames", 1) != 1:
                    raise ValueError("image_pixel_or_frame_limit")
                probe.verify()
            validated = perf_counter()
            with Image.open(io.BytesIO(contents)) as image:
                # JPEG draft reduces decode allocation before any full RGB copy.
                # Other formats still need a full decode; reject before allocation.
                budget = int(os.environ.get("AI_DECODE_BUDGET_MB", "384")) * 1024 * 1024
                if not legacy and image.format == "JPEG":
                    image.draft("RGB", (768, 768))
                # Do not introduce a new processing-budget rejection into the
                # production-validated /embed upload flow. Its full-image decode
                # is unchanged; the new /features path has the additional guard.
                if not legacy and image.width * image.height * 12 + len(contents) * 2 > budget:
                    raise ValueError("image_decode_budget")
                image.load()
                result = image.convert("RGB") if legacy else ImageOps.exif_transpose(image).convert("RGB")
        ended = perf_counter()
        return result, {"validation_ms": round((validated-started)*1000, 3),
                        "decode_ms": round((ended-validated)*1000, 3),
                        "validation_decode_ms": round((ended-started)*1000, 3)}
    except (OSError, SyntaxError, UnidentifiedImageError, Image.DecompressionBombError,
            Image.DecompressionBombWarning) as error:
        raise ValueError("invalid_image") from error


def segment(rgb: np.ndarray) -> np.ndarray:
    if cv2 is None:
        raise RuntimeError("segmenter_unavailable")
    cv2.setRNGSeed(0)
    height, width = rgb.shape[:2]
    mask = np.zeros((height, width), np.uint8)
    margin = max(1, round(min(height, width) * .02))
    cv2.grabCut(rgb, mask, (margin, margin, width - 2 * margin, height - 2 * margin),
                np.zeros((1, 65), np.float64), np.zeros((1, 65), np.float64),
                2, cv2.GC_INIT_WITH_RECT)
    return np.isin(mask, (cv2.GC_FGD, cv2.GC_PR_FGD)).astype(np.uint8)


def prepare(image: Image.Image, segmenter=segment) -> Prepared:
    started = perf_counter()
    timings = {key: 0.0 for key in ('resize_ms', 'grabcut_ms', 'component_selection_ms', 'crop_ms', 'normalization_ms')}
    working = image.copy()
    working.thumbnail((WORK_SIZE, WORK_SIZE), Image.Resampling.LANCZOS)
    rgb = np.asarray(working)
    timings['resize_ms'] = (perf_counter()-started)*1000
    height, width = rgb.shape[:2]
    metadata = {"version": VERSION, "mode": "original", "reason": "low_confidence",
                "working_size": [width, height], "bbox": [0, 0, width, height]}
    object_mask = None
    try:
        if min(width, height) < 16:
            raise ValueError("image_too_small")
        stage = perf_counter()
        mask = segmenter(rgb)
        timings['grabcut_ms'] = (perf_counter()-stage)*1000
        stage = perf_counter()
        if mask.shape != (height, width):
            raise ValueError("invalid_mask")
        count, labels, stats, centroids = cv2.connectedComponentsWithStats(mask.astype(np.uint8))
        candidates = []
        for label in range(1, count):
            area = int(stats[label, cv2.CC_STAT_AREA])
            distance = np.linalg.norm((centroids[label] - [width / 2, height / 2]) / [width, height])
            candidates.append((area * (1 - min(float(distance), .8)), label))
        if not candidates:
            raise ValueError("empty_mask")
        label = max(candidates)[1]
        x, y, w, h, area = [int(v) for v in stats[label]]
        fraction = area / (height * width)
        dominance = area / max(int(mask.sum()), 1)
        timings['component_selection_ms'] = (perf_counter()-stage)*1000
        # Do not cut off edge-filling products or choose among ambiguous objects.
        if not .03 <= fraction <= .88 or dominance < .65 or min(x, y) <= 1 or x+w >= width-1 or y+h >= height-1:
            raise ValueError("low_confidence")
        stage = perf_counter()
        pad = max(2, round(max(w, h) * .08))
        left, top, right, bottom = max(0, x-pad), max(0, y-pad), min(width, x+w+pad), min(height, y+h+pad)
        full_mask = (labels == label).astype(np.uint8)
        # Mild suppression preserves fine details at uncertain mask boundaries.
        alpha = full_mask[..., None] * .8 + .2
        isolated = np.clip(rgb * alpha + 127 * (1-alpha), 0, 255).astype(np.uint8)
        working = Image.fromarray(isolated[top:bottom, left:right])
        object_mask = full_mask[top:bottom, left:right]
        metadata.update(mode="segmented", reason="accepted", bbox=[left, top, right, bottom],
                        foreground_fraction=round(fraction, 4), dominance=round(dominance, 4))
        timings['crop_ms'] = (perf_counter()-stage)*1000
    except Exception as error:
        metadata["reason"] = str(error) if isinstance(error, ValueError) else "segmentation_unavailable_or_error"
    # Letterboxing avoids CLIP's center crop discarding the ends of long objects.
    stage = perf_counter()
    side = max(working.size)
    canvas = Image.new("RGB", (side, side), (127, 127, 127))
    offset = ((side-working.width)//2, (side-working.height)//2)
    canvas.paste(working, offset)
    if object_mask is not None:
        padded = np.zeros((side, side), np.uint8)
        padded[offset[1]:offset[1]+working.height, offset[0]:offset[0]+working.width] = object_mask
        object_mask = padded
    timings['normalization_ms'] = (perf_counter()-stage)*1000
    metadata['timings'] = {key: round(value, 3) for key, value in timings.items()}
    metadata["preprocessing_ms"] = round((perf_counter()-started)*1000, 3)
    return Prepared(canvas, object_mask, metadata)
