"""Replaceable foreground detector. GrabCut is a heuristic, not a product detector."""
from typing import Protocol
import numpy as np
from PIL import Image


class ForegroundExtractor(Protocol):
    def box(self, image: Image.Image) -> tuple[int, int, int, int] | None: ...


class GrabCutForeground:
    """Bound CPU cost and abstain on small, border-clipped or fragmented foreground."""
    def box(self, image: Image.Image) -> tuple[int, int, int, int] | None:
        import cv2
        small = image.copy()
        small.thumbnail((384, 384))
        data = np.asarray(small)
        h, w = data.shape[:2]
        if min(h, w) < 32:
            return None
        margin = max(2, int(min(h, w) * .04))
        mask = np.zeros((h, w), np.uint8)
        cv2.grabCut(data, mask, (margin, margin, w - 2 * margin, h - 2 * margin),
                    np.zeros((1, 65), np.float64), np.zeros((1, 65), np.float64),
                    3, cv2.GC_INIT_WITH_RECT)
        fg = np.isin(mask, (cv2.GC_FGD, cv2.GC_PR_FGD)).astype(np.uint8)
        count, _, stats, _ = cv2.connectedComponentsWithStats(fg)
        if count <= 1:
            return None
        best = stats[1:][np.argmax(stats[1:, cv2.CC_STAT_AREA])]
        x, y, bw, bh, area = map(int, best)
        if not .12 <= area / (h * w) <= .90 or area / max(1, fg.sum()) < .85:
            return None
        if x <= margin or y <= margin or x + bw >= w - margin or y + bh >= h - margin:
            return None
        # Keep all pixels in a padded box; never mask a hand/edge by skin color.
        px, py = max(4, int(bw * .15)), max(4, int(bh * .15))
        return (int(max(0, x - px) * image.width / w), int(max(0, y - py) * image.height / h),
                int(min(w, x + bw + px) * image.width / w),
                int(min(h, y + bh + py) * image.height / h))
