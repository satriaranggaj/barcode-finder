"""Offline-only object isolation experiment. Never imported by the HTTP service.

U2NetP predicts saliency, NOT product/hand classes. Mask quality must be reviewed.
Weights are local, checksum pinned; inference performs no network requests.
"""
import hashlib
from pathlib import Path
from time import perf_counter

import numpy as np
from PIL import Image
from app.preprocessing import Prepared, cv2
from app.conditional_preprocessing import prepare as geometric_prepare
from app.descriptors import describe

MODEL_SHA256 = '309c8469258dda742793dce0ebea8e6dd393174f89934733ecc8b14c76f4ddd8'
VERSION = 'object-first-u2netp-experiment-1'


class SaliencyModel:
    def __init__(self, path):
        import onnxruntime as ort
        path = Path(path)
        if hashlib.sha256(path.read_bytes()).hexdigest() != MODEL_SHA256:
            raise ValueError('segmentation_model_hash_mismatch')
        options = ort.SessionOptions()
        options.intra_op_num_threads = 2
        options.inter_op_num_threads = 1
        options.execution_mode = ort.ExecutionMode.ORT_SEQUENTIAL
        self.session = ort.InferenceSession(str(path), options, providers=['CPUExecutionProvider'])

    def __call__(self, image):
        rgb = np.asarray(image.resize((320, 320), Image.Resampling.LANCZOS), dtype=np.float32)
        rgb /= max(float(rgb.max()), 1)
        rgb = (rgb - np.array([.485, .456, .406], np.float32)) / np.array([.229, .224, .225], np.float32)
        tensor = rgb.transpose(2, 0, 1)[None]
        pred = self.session.run(None, {self.session.get_inputs()[0].name: tensor})[0][0, 0]
        if not np.isfinite(pred).all():
            raise ValueError('invalid_segmentation_output')
        span = float(pred.max() - pred.min())
        probability = (pred-pred.min())/span if span > 1e-6 else np.zeros_like(pred)
        return cv2.resize(probability, image.size, interpolation=cv2.INTER_LINEAR)


def prepare(image, segmenter, *, allow_fast_path=True):
    start = perf_counter()
    # Geometric confidence is deliberately not called semantic confidence.
    fast = geometric_prepare(image, segmenter=lambda rgb: np.zeros(rgb.shape[:2], np.uint8))
    if allow_fast_path and fast.metadata['mode'] == 'clean_crop':
        fast.metadata.update(version=VERSION, path='geometric_fast', hand_suppression='unverified')
        return fast
    working = image.copy()
    working.thumbnail((256, 256), Image.Resampling.LANCZOS)
    meta = dict(version=VERSION, path='learned_saliency', hand_suppression='unsupported',
                model_sha256=MODEL_SHA256, accepted=False)
    try:
        probability = segmenter(working)
        if probability.shape != (working.height, working.width) or not np.isfinite(probability).all():
            raise ValueError('invalid_mask')
        # 0.5 is the mask decision boundary, NOT a search relevance threshold.
        mask = (probability >= .5).astype(np.uint8)
        fraction = float(mask.mean())
        if not .01 < fraction < .95:
            raise ValueError('empty_or_full_mask')
        ys, xs = np.where(mask)
        pad = max(2, round(max(xs.ptp() if hasattr(xs, 'ptp') else np.ptp(xs), np.ptp(ys))*.08))
        left, top = max(0, int(xs.min())-pad), max(0, int(ys.min())-pad)
        right, bottom = min(working.width, int(xs.max())+pad+1), min(working.height, int(ys.max())+pad+1)
        rgb = np.asarray(working)
        # Keep all salient components: do not delete an occluded product fragment.
        alpha = probability[..., None]
        rgb = np.clip(rgb*alpha + 127*(1-alpha), 0, 255).astype(np.uint8)
        cropped = Image.fromarray(rgb[top:bottom, left:right])
        side = max(cropped.size)
        canvas = Image.new('RGB', (side, side), (127, 127, 127))
        offset = ((side-cropped.width)//2, (side-cropped.height)//2)
        canvas.paste(cropped, offset)
        padded = np.zeros((side, side), np.uint8)
        padded[offset[1]:offset[1]+cropped.height, offset[0]:offset[0]+cropped.width] = mask[top:bottom, left:right]
        meta.update(accepted=True, bbox=[left, top, right, bottom], foreground_fraction=fraction)
        result = Prepared(canvas, padded, meta)
    except (ValueError, RuntimeError) as error:
        # No new crop on uncertain output. Existing bounded geometric fallback.
        result = geometric_prepare(image)
        result.metadata.update(meta, reason=str(error), path='geometric_fallback')
    result.metadata['preprocessing_ms'] = (perf_counter()-start)*1000
    return result


def features(prepared):
    """Reference-side precomputation for offline Top-K experiments only."""
    result = describe(prepared)
    if prepared.mask is None or not prepared.mask.any():
        result['pattern'] = None
        return result
    gray = cv2.cvtColor(np.asarray(prepared.image), cv2.COLOR_RGB2GRAY)
    gx, gy = cv2.Sobel(gray, cv2.CV_32F, 1, 0), cv2.Sobel(gray, cv2.CV_32F, 0, 1)
    magnitude, angle = cv2.cartToPolar(gx, gy)
    cells = []
    # Spatial orientation histogram: motif direction and position, 4 x 8 bins.
    for rows in np.array_split(np.arange(gray.shape[0]), 2):
        for cols in np.array_split(np.arange(gray.shape[1]), 2):
            selected = prepared.mask[np.ix_(rows, cols)] > 0
            hist = np.histogram(angle[np.ix_(rows, cols)][selected] % np.pi, bins=8,
                                range=(0, np.pi), weights=magnitude[np.ix_(rows, cols)][selected])[0]
            cells.extend(hist.tolist())
    total = sum(cells)
    result['pattern'] = [x/total for x in cells] if total else None
    return result


def fuse(global_score, query, reference, weights, penalties):
    """Explicit experimental presets; missing/invalid evidence never penalizes."""
    signals = {}
    for name in ('shape', 'color', 'texture', 'pattern', 'proportion'):
        a, b = query.get(name), reference.get(name)
        if a is None or b is None:
            continue
        a, b = np.asarray(a, dtype=float), np.asarray(b, dtype=float)
        if a.shape != b.shape or not np.isfinite(a).all() or not np.isfinite(b).all() or (a < 0).any() or (b < 0).any():
            continue
        if name == 'proportion':
            if a.size == 1 and float(a) > 0 and float(b) > 0:
                signals[name] = min(float(a/b), float(b/a))
        elif a.sum() > 0 and b.sum() > 0:
            signals[name] = float(np.minimum(a/a.sum(), b/b.sum()).sum())
    for config in (weights, penalties):
        if any(k not in {'global', 'shape', 'color', 'texture', 'pattern', 'proportion'} or not np.isfinite(v) or v < 0 for k, v in config.items()):
            raise ValueError('invalid_fusion_configuration')
    available = dict(global_=global_score, **signals)
    available['global'] = available.pop('global_')
    total = sum(weights.get(k, 0) for k in available)
    score = sum(weights.get(k, 0)*v for k, v in available.items())/total if total else global_score
    score -= sum(penalties.get(k, 0)*(1-v) for k, v in signals.items())
    return float(np.clip(score, -1, 1))
