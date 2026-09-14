"""The retrieval pipeline depends only on this encoder contract."""
from abc import ABC, abstractmethod
import numpy as np
from PIL import Image


def normalize(vectors: np.ndarray) -> np.ndarray:
    values = np.asarray(vectors, dtype=np.float32)
    lengths = np.linalg.norm(values, axis=-1, keepdims=True)
    if not np.isfinite(values).all() or np.any(lengths <= 1e-12):
        raise ValueError('Invalid/zero embedding')
    return np.ascontiguousarray(values / lengths, dtype=np.float32)


class ImageEncoder(ABC):
    """A future metric-learning encoder can implement this same contract."""
    identity: dict

    @abstractmethod
    def encode_images(self, images: list[Image.Image]) -> np.ndarray:
        """Return [batch, dimensions] normalized float32 vectors."""

    def encode_image(self, image: Image.Image) -> np.ndarray:
        return self.encode_images([image])[0]
