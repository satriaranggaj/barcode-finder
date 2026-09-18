"""Typed POST /search contract.

Request fields keep their existing multipart names; the response models mirror
exactly what RetrievalService returns so response_model validates (not silently
reshapes) the payload. Scores are cosine-style similarities in [-1, 1], never
probabilities. Full embedding vectors, absolute paths and credentials must
never appear here; contract tests scan for them.
"""
from pydantic import BaseModel, Field


class AmbiguityAlternative(BaseModel):
    sku: str
    score: float = Field(ge=-1.0, le=1.0)
    family: str | None = None


class SearchCandidate(BaseModel):
    rank: int = Field(ge=1)
    sku: str
    product_id: int | str | None = None
    image_id: str
    family: str | None = None
    sub_category: str | None = None
    score: float = Field(ge=-1.0, le=1.0)
    visual_score: float = Field(ge=-1.0, le=1.0)
    object_score: float | None = Field(default=None, ge=-1.0, le=1.0)
    global_score: float | None = Field(default=None, ge=-1.0, le=1.0)
    ocr_score: float | None = Field(default=None, ge=-1.0, le=1.0)
    text_score: float | None = Field(default=None, ge=-1.0, le=1.0)
    siglip_score: float | None = Field(default=None, ge=-1.0, le=1.0)
    dino_score: float | None = Field(default=None, ge=-1.0, le=1.0)
    local_score: float | None = Field(default=None, ge=-1.0, le=1.0)
    matched_images: int = Field(ge=1)
    matched_image_ids: list[str] = Field(default_factory=list)


class SearchQueryInfo(BaseModel):
    preprocessing: str
    reason: str
    requested_preprocessing: str
    models: list[str] = Field(default_factory=list)
    selection_used: dict[str, float] | None = None
    object_representation: bool = False
    selection_mode: str | None = None
    ocr_tokens: list[str] = Field(default_factory=list)
    evaluation_signature: dict = Field(default_factory=dict)


class FeedbackPreview(BaseModel):
    master: str
    photo_hash: str
    dhash: str
    blur: float
    gray_std: float
    crop_pixels: int
    min_side: int


class SearchResponse(BaseModel):
    success: bool
    query: SearchQueryInfo
    confidence: str
    score_gap: float | None = None
    confidence_calibrated: bool
    ambiguous: bool
    ambiguity_reason: str | None = None
    ambiguity_alternatives: list[AmbiguityAlternative] = Field(default_factory=list)
    candidate_images: int = Field(ge=0)
    relevance_calibrated: bool
    latency_ms: float
    # Per-stage durations in ms (selection, preprocess, encode, faiss,
    # references, ocr, rerank); diagnostics only, no sensitive internals.
    stage_ms: dict[str, float] = Field(default_factory=dict)
    results: list[SearchCandidate] = Field(default_factory=list)
    feedback: FeedbackPreview | None = None
