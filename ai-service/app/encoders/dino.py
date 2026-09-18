"""DINOv2 global CLS feature plus normalized spatial patch tokens.

Patch tokens express visual local correspondence only; no semantic part
assumptions. The full token grid is exposed together with a padding-validity
mask so callers can discard tokens that fall on square-padding filler.
"""
from dataclasses import dataclass
import numpy as np
from .base import normalize
from .huggingface import HuggingFaceEncoder

MIN_PATCH_EVIDENCE = 4


def spatial_validity(image_size: tuple[int, int], side: int, grid: int) -> np.ndarray:
    """Mask of patch-token centers inside the content box of a pad_square layout.

    pad_square centers the image on a gray (127, 127, 127) square; patch tokens
    whose centers fall outside the original image describe padding, not content.
    """
    left = (side - image_size[0]) / (2 * side)
    top = (side - image_size[1]) / (2 * side)
    yy, xx = np.meshgrid((np.arange(grid) + .5) / grid,
                         (np.arange(grid) + .5) / grid, indexing='ij')
    return ((xx >= left) & (xx <= 1 - left) & (yy >= top) & (yy <= 1 - top)).flatten()


@dataclass(frozen=True)
class PatchFeatures:
    """Full normalized patch grid plus its content-validity mask."""
    features: np.ndarray  # [grid*grid, dim] float32, L2-normalized
    valid: np.ndarray     # [grid*grid] bool; True where the token covers content
    grid: int

    def __post_init__(self):
        if self.features.ndim != 2 or self.features.shape[0] != self.grid * self.grid:
            raise ValueError('PatchFeatures: features must be [grid*grid, dim]')
        if self.valid.shape != (self.grid * self.grid,):
            raise ValueError('PatchFeatures: valid mask must have grid*grid entries')
        if self.valid.dtype != np.bool_:
            object.__setattr__(self, 'valid', self.valid.astype(np.bool_))

    @property
    def content(self) -> np.ndarray:
        """Padding-masked tokens, still in grid order."""
        return self.features[self.valid]

    def compact(self, limit: int) -> np.ndarray:
        """Masked tokens capped to `limit` by even grid sampling."""
        if limit < 1:
            raise ValueError('Patch limit must be at least 1')
        values = self.content
        if len(values) > limit:
            values = values[np.linspace(0, len(values) - 1, limit, dtype=int)]
        return values


class DinoEncoder(HuggingFaceEncoder):
    """DINOv2 normalized CLS representation for visual appearance retrieval."""

    def __init__(self, model_id: str, revision: str, device: str, batch_size: int = 2):
        super().__init__(model_id, revision, device, batch_size, 'cls')

    def patch_features(self, image) -> PatchFeatures:
        from ..preprocessing.pipeline import pad_square
        side = max(image.size)
        inputs = self.processor(images=[pad_square(image)], return_tensors='pt', do_center_crop=False)
        inputs = {key: value.to(self.device) for key, value in inputs.items()}
        with self.torch.inference_mode():
            # DINOv2 token zero is CLS; remaining tokens are spatial patches.
            values = self.model(**inputs).last_hidden_state[0, 1:].float().cpu().numpy()
        grid = int(np.sqrt(len(values)))
        if grid * grid != len(values):
            raise ValueError('Unexpected DINO spatial token layout')
        mask = spatial_validity(image.size, side, grid)
        if mask.sum() < MIN_PATCH_EVIDENCE:
            raise ValueError('Object too narrow for robust patch evidence')
        return PatchFeatures(features=normalize(values), valid=mask, grid=grid)

    def encode_patches(self, image, limit: int = 64) -> np.ndarray:
        """Compact normalized content-patch tokens; kept for storage/ranking use."""
        return self.patch_features(image).compact(limit)
