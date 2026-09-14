"""Configuration shared by API, indexing and evaluation (environment only)."""
from dataclasses import dataclass
from pathlib import Path
import math
import os


@dataclass(frozen=True)
class Settings:
    siglip_model: str = 'google/siglip2-base-patch16-256'
    dino_model: str = 'facebook/dinov2-small'
    siglip_revision: str = 'main'
    dino_revision: str = 'main'
    device: str = 'auto'
    siglip_weight: float = .35
    dino_weight: float = .65
    local_weight: float = .25
    index_path: Path = Path('indexes')
    default_top_k: int = 5
    candidates: int = 50
    preprocessing_mode: str = 'object'
    max_bytes: int = 20 * 1024 * 1024
    max_pixels: int = 40_000_000
    max_side: int = 1024
    batch_size: int = 2
    cpu_threads: int = 4
    high_score: float = .85
    high_gap: float = .08
    medium_score: float = .70
    legacy_embed: bool = True

    def __post_init__(self) -> None:
        numbers = (self.siglip_weight, self.dino_weight, self.local_weight,
                   self.high_score, self.high_gap, self.medium_score)
        if not all(math.isfinite(n) for n in numbers):
            raise ValueError('Scores must be finite')
        if min(self.siglip_weight, self.dino_weight) < 0 or not math.isclose(
                self.siglip_weight + self.dino_weight, 1, abs_tol=1e-6):
            raise ValueError('SIGLIP_WEIGHT + DINO_WEIGHT must equal 1; weights must be nonnegative')
        if not 0 <= self.local_weight <= 1:
            raise ValueError('LOCAL_WEIGHT must be between 0 and 1')
        if not -1 <= self.medium_score <= self.high_score <= 1 or not 0 <= self.high_gap <= 2:
            raise ValueError('Invalid confidence thresholds')
        if not 1 <= self.default_top_k <= 50 or not 50 <= self.candidates <= 500:
            raise ValueError('top_k must be 1..50, candidates 50..500')
        if self.preprocessing_mode not in ('object', 'original') or self.device not in ('auto', 'cpu', 'cuda'):
            raise ValueError('Invalid preprocessing mode/device')
        if min(self.max_bytes, self.max_pixels, self.max_side, self.batch_size, self.cpu_threads) < 1:
            raise ValueError('Resource limits must be positive')

    @classmethod
    def from_env(cls) -> 'Settings':
        defaults = cls()
        values = {}
        aliases = {'index_path': 'FAISS_INDEX_PATH', 'legacy_embed': 'ENABLE_LEGACY_EMBED'}
        for name in cls.__dataclass_fields__:
            raw = os.getenv(aliases.get(name, name.upper()))
            if raw is None:
                continue
            default = getattr(defaults, name)
            if isinstance(default, bool):
                if raw.lower() not in ('true', 'false', '1', '0'):
                    raise ValueError(f'Invalid boolean: {name}')
                values[name] = raw.lower() in ('true', '1')
            else:
                values[name] = type(default)(raw)
        return cls(**values)

    @property
    def weights(self) -> dict[str, float]:
        return {'siglip': self.siglip_weight, 'dino': self.dino_weight}
