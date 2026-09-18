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
    # Sub-crop detail blend inside object-vs-object matching. Raised 0.25→0.5
    # on held-out eval evidence (top-1 0.0→0.5, mrr 0.42→0.67 on the
    # fine-grained screwdriver set): small on-object details must outweigh the
    # global crop average, while the global term still anchors composition.
    local_weight: float = .5
    index_path: Path = Path('indexes')
    default_top_k: int = 5
    candidates: int = 50
    preprocessing_mode: str = 'object'
    max_bytes: int = 20 * 1024 * 1024
    max_pixels: int = 40_000_000
    max_side: int = 1024
    master_quality: int = 88
    catalog_quality: int = 83
    thumbnail_quality: int = 78
    catalog_side: int = 1600
    thumbnail_side: int = 400
    batch_size: int = 2
    cpu_threads: int = 4
    ambiguity_margin: float = .05
    high_score: float = .85
    high_gap: float = .08
    medium_score: float = .70
    legacy_embed: bool = True
    selection_padding: float = .08
    global_weight: float = 0.0
    rank_bias: float = 60.0
    object_index_weight: float = 0.8
    candidates_per_sku: int = 3
    patch_weight: float = 0.0
    max_patches: int = 64
    patch_aggregation: str = 'top_k'
    patch_top_k: int = 16
    patch_trim: float = 0.1
    secondary_weight: float = 0.0
    description_text_weight: float = 0.0
    ocr_enabled: bool = False
    ocr_binary: str = 'tesseract'
    ocr_min_confidence: int = 80
    relevance_policy: str = ''
    processing_memory_mb: int = 256
    # Accepted covered-area window for object proposals: smaller regions are
    # noise, near-full-frame boxes are not a usable crop.
    selection_min_area: float = .02
    selection_max_area: float = .92

    def __post_init__(self) -> None:
        if not 0 <= self.selection_padding <= .25 or not 0 <= self.global_weight <= 1:
            raise ValueError('Invalid selection/global weight')
        if not 0 < self.selection_min_area < self.selection_max_area <= 1:
            raise ValueError('SELECTION_MIN_AREA/SELECTION_MAX_AREA must satisfy 0 < min < max <= 1')
        if not math.isfinite(self.rank_bias) or self.rank_bias < 0:
            raise ValueError('Rank fusion bias must be finite and nonnegative')
        if not 0 <= self.object_index_weight <= 1:
            raise ValueError('Object index weight must be 0..1')
        if not 1 <= self.candidates_per_sku <= 50:
            raise ValueError('Per-SKU candidate cap must be 1..50')
        if not 0 <= self.patch_weight <= 1 or not 4 <= self.max_patches <= 256:
            raise ValueError('Invalid patch configuration')
        if self.patch_aggregation not in ('top_k', 'median', 'trimmed_mean', 'mean'):
            raise ValueError('PATCH_AGGREGATION must be top_k, median, trimmed_mean or mean')
        if not 4 <= self.patch_top_k <= 256 or not 0 <= self.patch_trim < .5:
            raise ValueError('Invalid patch aggregation parameters')
        if not 0 <= self.secondary_weight < .5:
            raise ValueError('Visual evidence must remain the majority')
        if not 0 <= self.description_text_weight <= 1:
            raise ValueError('Invalid text fusion weight')
        if not 0 <= self.ocr_min_confidence <= 100:
            raise ValueError('Invalid OCR confidence cutoff')
        numbers = (self.siglip_weight, self.dino_weight, self.local_weight,
                   self.high_score, self.high_gap, self.medium_score)
        if not all(math.isfinite(n) for n in numbers):
            raise ValueError('Scores must be finite')
        if min(self.siglip_weight, self.dino_weight) < 0 or not math.isclose(
                self.siglip_weight + self.dino_weight, 1, abs_tol=1e-6):
            raise ValueError('SIGLIP_WEIGHT + DINO_WEIGHT must equal 1; weights must be nonnegative')
        if not 0 <= self.local_weight <= 1:
            raise ValueError('LOCAL_WEIGHT must be between 0 and 1')
        if not 0 <= self.ambiguity_margin <= 2:
            raise ValueError('Ambiguity margin must be between 0 and 2')
        if not -1 <= self.medium_score <= self.high_score <= 1 or not 0 <= self.high_gap <= 2:
            raise ValueError('Invalid confidence thresholds')
        if not 1 <= self.default_top_k <= 50 or not 50 <= self.candidates <= 500:
            raise ValueError('top_k must be 1..50, candidates 50..500')
        if self.preprocessing_mode not in ('object', 'original') or self.device not in ('auto', 'cpu', 'cuda'):
            raise ValueError('Invalid preprocessing mode/device')
        if min(self.max_bytes, self.max_pixels, self.max_side, self.batch_size, self.cpu_threads, self.processing_memory_mb) < 1:
            raise ValueError('Resource limits must be positive')
        if not all(75 <= q <= 95 for q in (self.master_quality, self.catalog_quality, self.thumbnail_quality)):
            raise ValueError('WebP quality must be 75..95')
        if not 1200 <= self.catalog_side <= 1600 or not 300 <= self.thumbnail_side <= 500:
            raise ValueError('Derivative side limits out of range')

    @classmethod
    def from_env(cls, env_file: Path | None = None) -> 'Settings':
        # Developer convenience: ai-service/.env is auto-loaded when present
        # (gitignored, like the root .env). Real environment variables always
        # win, so container/orchestrator injection keeps working unchanged.
        # Without both python-dotenv and a file, behaviour is exactly as before.
        if env_file is None:
            env_file = Path(__file__).resolve().parent.parent / '.env'
        if env_file.is_file():
            try:
                from dotenv import load_dotenv
            except ImportError:
                pass
            else:
                load_dotenv(env_file, override=False)
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
