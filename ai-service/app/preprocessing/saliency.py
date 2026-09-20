"""Classical saliency + proposal scoring shared by every object selector.

No model and no extra dependency: spectral-residual saliency (Hou & Zhang) is
implemented with numpy FFT, and `box_quality` converts one box into a ranking
heuristic in 0..1. Every value here is a heuristic ordering aid for proposals
that the user can always edit — never a detection probability.
"""
import math

import numpy as np

# Ranking weights: how much each heuristic term contributes to `box_quality`.
# They are relative, not calibrated, and mirror the env-configurable defaults.
# Centrality carries real weight because staff photos centre the product, which
# keeps small corner patches from outranking the object.
QUALITY_WEIGHTS = {
    'saliency': .35,
    'size': .3,
    'centrality': .25,
    'aspect': .1,
}
# Box coverage fraction that scores best; smaller patches and near-full-frame
# boxes are both penalised because neither isolates a product.
IDEAL_AREA = .3
# Aspect ratio (long side / short side) considered natural for a product box.
IDEAL_ASPECT = 1.25
MIN_PLAUSIBLE_AREA = .015


def spectral_residual(gray: np.ndarray, blur: int = 5) -> np.ndarray:
    """Saliency map from log-spectrum residual, normalized to 0..1 float32.

    `gray` is a 2-D float array. Cost is one FFT pair at thumbnail scale, so
    callers must downsample first (the detectors use 384 px).
    """
    spectrum = np.fft.fft2(gray)
    magnitude = np.abs(spectrum)
    phase = np.angle(spectrum)
    log_amplitude = np.log(magnitude + 1e-8)
    # Average log-spectrum carries redundant (background) structure.
    residual = log_amplitude - _mean_filter(log_amplitude, blur)
    saliency = np.abs(np.fft.ifft2(np.exp(residual + 1j * phase))) ** 2
    saliency = _mean_filter(np.fft.fftshift(saliency).astype(np.float32), blur)
    peak = float(saliency.max())
    if not np.isfinite(peak) or peak <= 0:
        return np.zeros_like(saliency, dtype=np.float32)
    return (saliency / peak).astype(np.float32)


def _mean_filter(data: np.ndarray, size: int) -> np.ndarray:
    """Box blur via cumulative sums — no cv2 dependency at import time."""
    if size <= 1:
        return data.astype(np.float32)
    radius = size // 2
    padded = np.pad(data.astype(np.float32), radius, mode='edge')
    cumulative = np.cumsum(np.cumsum(padded, axis=0), axis=1)
    cumulative = np.pad(cumulative, ((1, 0), (1, 0)))
    window = size
    top = cumulative[:-window, :-window]
    bottom = cumulative[window:, :-window]
    left = cumulative[:-window, window:]
    right = cumulative[window:, window:]
    return ((right - left - bottom + top) / (window * window)).astype(np.float32)


def size_plausibility(fraction: float) -> float:
    """1.0 near IDEAL_AREA, decaying towards 0 for tiny or near-full boxes."""
    if fraction <= 0:
        return 0.0
    if fraction >= 1:
        return 0.0
    if fraction <= IDEAL_AREA:
        return max(0.0, fraction / IDEAL_AREA)
    return max(0.0, (1 - fraction) / (1 - IDEAL_AREA))


def centrality(box, width: int, height: int) -> float:
    """1.0 at the image centre, 0.0 at the border (normalized, aspect-agnostic)."""
    x, y, bw, bh = box
    offset_x = abs(x + bw / 2 - width / 2) / max(1.0, width / 2)
    offset_y = abs(y + bh / 2 - height / 2) / max(1.0, height / 2)
    return float(max(0.0, 1 - min(1.0, offset_x * .5 + offset_y * .5)))


def aspect_plausibility(box) -> float:
    """1.0 for a natural long/short ratio, decaying for strips and slivers.

    The decay is deliberately gentle past the ideal: tall thin tools
    (screwdrivers, poles, obeng plus/minus panjang/pendek) are legitimate
    products, so a ratio of ~5 keeps half credit and ~9 still keeps ~0.3;
    only extreme slivers (ratio > 15) score near zero. Structural strip
    rejection (full-width bands, contour gates) lives in the detectors.
    """
    _, _, bw, bh = box
    if bw <= 0 or bh <= 0:
        return 0.0
    ratio = max(bw, bh) / min(bw, bh)
    return float(max(0.0, 1 - min(1.0, abs(np.log(ratio / IDEAL_ASPECT)) / 2.8)))


def box_quality(box, width: int, height: int, saliency: np.ndarray | None = None,
                weights: dict | None = None) -> float:
    """Heuristic ranking score in 0..1 for one pixel box on a thumbnail.

    Terms: mean saliency inside the box (when a map is supplied), size
    plausibility, centrality and aspect plausibility. Missing terms are dropped
    and the remaining weights renormalized, so a detector without a saliency
    map still produces a usable ordering.
    """
    x, y, bw, bh = (int(round(v)) for v in (box[0], box[1], box[2], box[3]))
    if bw <= 0 or bh <= 0 or width <= 0 or height <= 0:
        return 0.0
    active = dict(weights or QUALITY_WEIGHTS)
    terms = {
        'size': size_plausibility(bw * bh / (width * height)),
        'centrality': centrality(box, width, height),
        'aspect': aspect_plausibility(box),
    }
    if saliency is not None and saliency.size:
        region = saliency[max(0, y):y + bh, max(0, x):x + bw]
        # Blend explained saliency mass with density. Mass alone favours the
        # whole frame, density alone favours a small glare patch; the geometric
        # mean keeps a product-sized region ahead of both.
        if region.size:
            mass = float(region.sum()) / max(1e-6, float(saliency.sum()))
            density = float(region.mean()) / max(1e-6, float(saliency.mean()))
            terms['saliency'] = math.sqrt(min(1.0, mass) * min(1.0, density / 3))
        else:
            terms['saliency'] = 0.0
    else:
        active.pop('saliency', None)
    total = sum(active.get(name, 0.0) for name in terms)
    if total <= 0:
        return 0.0
    return float(max(0.0, min(1.0, sum(active.get(name, 0.0) * value for name, value in terms.items()) / total)))
