"""Shared orchestration for API, offline indexing and evaluation."""
import logging
import time
import json
import hashlib
from pathlib import Path
from dataclasses import asdict
import faiss
from PIL import Image
from ..config import Settings
from ..encoders.base import ImageEncoder, normalize
from ..preprocessing.pipeline import Preprocessor, pad_square, VERSION, photo_hash
from ..preprocessing.crop import multi_crop
from ..preprocessing.selection import BoundingBox, SelectionPipeline, TOLERANCE, default_pipeline
from ..features.ocr import DisabledOCR, TextExtractor, TesseractOCR, confidence_weight
from ..features.attributes import parse_attributes, compatibility, VERSION as ATTRIBUTE_VERSION
from .faiss_index import FaissIndexManager
from .ranking import model_score, weighted_score, aggregate_skus, ambiguity, confidence, patch_similarity, fuse_ranks

logger = logging.getLogger(__name__)
SOURCE_ROOT = Path(__file__).resolve().parents[1]
REPRESENTATION_CODE = {name: hashlib.sha256((SOURCE_ROOT/name).read_bytes()).hexdigest() for name in (
    'encoders/huggingface.py', 'encoders/dino.py', 'preprocessing/pipeline.py',
    'preprocessing/selection.py', 'preprocessing/foreground.py', 'preprocessing/saliency.py',
    'preprocessing/crop.py', 'search/service.py')}
RANKING_CODE = hashlib.sha256(Path(__file__).read_bytes() + (SOURCE_ROOT/'search/ranking.py').read_bytes()
                              + (SOURCE_ROOT/'features/attributes.py').read_bytes() + (SOURCE_ROOT/'features/ocr.py').read_bytes()
                              + (SOURCE_ROOT/'search/faiss_index.py').read_bytes()).hexdigest()


def is_object_box(box: BoundingBox | None) -> bool:
    """True for a real object region; full-image boxes carry no object signal."""
    if box is None:
        return False
    return (box.x > TOLERANCE or box.y > TOLERANCE
            or box.width < 1 - TOLERANCE or box.height < 1 - TOLERANCE)


def clip_similarity(value: float) -> float:
    """Clamp a cosine-style similarity to [-1, 1].

    float32 dot products of normalized vectors overshoot by ~1e-7 on
    near-identical images, which the API schema (and any consumer) rejects.
    """
    return max(-1.0, min(1.0, float(value)))


def reference_attributes(extra: dict) -> dict:
    """Trusted stored parse when its version matches, else a live parse.

    Sidecars written at build time already carry parsed_attributes; reusing
    them keeps query/reference comparison consistent and avoids recompute.
    """
    stored = extra.get('parsed_attributes')
    result = None
    if isinstance(stored, dict) and stored.get('version') == ATTRIBUTE_VERSION:
        values = stored.get('values')
        if isinstance(values, dict) and all(isinstance(v, list) and all(isinstance(x, str) for x in v) for v in values.values()):
            result = {key: list(value) for key, value in values.items()}
    if result is None:
        result = parse_attributes(extra.get('description', ''))
    trusted = extra.get('trusted_attributes')
    if isinstance(trusted, dict):
        for key, values in trusted.items():
            if isinstance(key, str) and isinstance(values, list) and values and all(isinstance(v, str) and v for v in values):
                result[key] = sorted(set(values))
    return result


def reference_has_object(extra: dict) -> bool:
    """Whether the reference embedding used a true object crop (stored sidecar metadata)."""
    crop = extra.get('crop')
    if not isinstance(crop, dict):
        return False
    try:
        return is_object_box(BoundingBox(**crop))
    except (TypeError, ValueError):
        return False


class RetrievalService:
    def __init__(self, settings: Settings, encoders: dict[str, ImageEncoder],
                 index: FaissIndexManager | None = None, ocr: TextExtractor | None = None,
                 pipeline: SelectionPipeline | None = None):
        self.settings, self.encoders, self.index = settings, encoders, index
        self.preprocessor = Preprocessor(settings.max_side)
        self.ocr = ocr or (TesseractOCR(settings.ocr_binary, settings.ocr_min_confidence) if settings.ocr_enabled else DisabledOCR())
        # Shared auto-selection with the /select UI and the index build: the
        # same photo must propose the same box in all three places.
        self.pipeline = pipeline or default_pipeline(
            self.settings.selection_padding,
            self.settings.selection_min_area, self.settings.selection_max_area)
        self.policy = None
        if settings.relevance_policy:
            if index is None or not getattr(index,'fingerprint',None):
                raise ValueError('Frozen relevance policy requires an immutable published index')
            self.policy = json.loads(Path(settings.relevance_policy).read_text(encoding='utf-8'))
            if self.policy.get('synthetic') or not self.policy.get('frozen') or self.policy.get('signature') != self.evaluation_signature:
                raise ValueError('Relevance policy incompatible; recalibrate before enabling')
            threshold = self.policy.get('threshold')
            if not isinstance(threshold, (float,int)) or not __import__('math').isfinite(threshold):
                raise ValueError('Invalid frozen threshold')
        if index is not None:
            expected = self.signature
            if index.signature['preprocessing'] != expected['preprocessing']:
                raise ValueError('Preprocessing changed; rebuild index')
            for name, encoder in encoders.items():
                if name in index.signature['models'] and index.signature['models'][name] != encoder.identity:
                    raise ValueError(f'{name} revision changed; rebuild index')

    @property
    def signature(self) -> dict:
        return {'models': {name: encoder.identity for name, encoder in self.encoders.items()},
                'preprocessing': {'version': 'object-centric-v4', 'max_side': self.settings.max_side,
                                  'code': REPRESENTATION_CODE,
                                  'reference_mode': self.settings.preprocessing_mode,
                                  'selection_padding': self.settings.selection_padding,
                                  'decode_memory_mb': self.settings.processing_memory_mb,
                                  'description_text': self.settings.description_text_weight > 0,
                                  'patch_version': 'masked-square-v2',
                                  'patches': self.settings.max_patches if self.settings.patch_weight > 0 else 0}}

    def embed(self, image: Image.Image, mode: str, box: BoundingBox | None = None, description: str = '',
                timings: dict | None = None) -> tuple[dict, dict]:
        clock = time.perf_counter
        mark = clock()
        reason = 'manual_selection' if box else 'original_requested'
        if box is None and mode == 'object':
            # Pipeline guarantees a full-image fallback; a failed proposal never
            # runs a second detector with different padding.
            proposal = self.pipeline.propose(image)
            reason = proposal['reason']
            if proposal['boxes']:
                box = BoundingBox(**proposal['boxes'][0])
        if timings is not None:
            timings['selection_ms'] = timings.get('selection_ms', 0.0) + (clock() - mark) * 1000
            mark = clock()
        prepared = self.preprocessor.prepare(box.crop(image) if box else image, 'original')
        crops = multi_crop(prepared.image)
        context = self.preprocessor.prepare(image, 'original').image
        crops['context'] = context
        if timings is not None:
            timings['preprocess_ms'] = timings.get('preprocess_ms', 0.0) + (clock() - mark) * 1000
            mark = clock()
        images = [pad_square(crop) for crop in crops.values()]
        unique, positions, identities = [], [], {}
        for crop in images:
            digest = photo_hash(crop)
            if digest not in identities:
                identities[digest] = len(unique); unique.append(crop)
            positions.append(identities[digest])
        representations = {}
        for name, encoder in self.encoders.items():
            try:
                vectors = normalize(encoder.encode_images(unique))
                if vectors.ndim != 2 or vectors.shape[0] != len(unique):
                    raise ValueError('Encoder returned an invalid crop batch')
                representations[name] = dict(zip(crops, vectors[positions]))
                if name == 'siglip' and self.settings.description_text_weight > 0:
                    representations[name]['description'] = encoder.encode_text(description) if description else representations[name]['global']
            except Exception:
                representations.pop(name, None)
                logger.exception('Encoder unavailable: %s', name)
        # Patch extraction is deliberately outside the per-encoder block above:
        # a patch failure must not discard the DINO global representation. Masked
        # tokens keep square padding from becoming patch evidence.
        if self.settings.patch_weight > 0 and 'dino' in representations:
            encoder = self.encoders['dino']
            try:
                if not hasattr(encoder, 'encode_patches'):
                    raise RuntimeError('Patch matching requires DINO patch-token support')
                representations['dino']['patches'] = encoder.encode_patches(prepared.image, self.settings.max_patches)
            except Exception:
                logger.exception('Patch extraction failed; dino global retained')
        if not representations:
            raise RuntimeError('No encoder available')
        if timings is not None:
            timings['encode_ms'] = timings.get('encode_ms', 0.0) + (clock() - mark) * 1000
        return representations, {'preprocessing': 'object' if box else 'original', 'reason': reason,
                                 'requested_preprocessing': mode, 'models': list(representations),
                                 'selection_used': asdict(box) if box else None,
                                 'object_representation': is_object_box(box)}

    def search(self, image: Image.Image, top_k: int = 5, category: str | None = None,
               mode: str = 'object', box: BoundingBox | None = None) -> dict:
        if not 1 <= top_k <= 50:
            raise ValueError('top_k must be 1..50')
        if self.index is None:
            raise RuntimeError('Index missing; build index first')
        start = time.perf_counter()
        clock = time.perf_counter
        faiss.omp_set_num_threads(self.settings.cpu_threads)
        # Every stage always reported (0.0 when skipped) for stable dashboards.
        stage_ms: dict[str, float] = {stage: 0.0 for stage in (
            'selection_ms', 'preprocess_ms', 'encode_ms', 'faiss_ms',
            'references_ms', 'ocr_ms', 'text_ms', 'rerank_ms')}
        query, info = self.embed(image, mode, box, timings=stage_ms)
        available = {name: crops for name, crops in query.items()
                     if name in self.index.signature['models'] and self.settings.weights[name] > 0}
        if not available:
            raise RuntimeError('No compatible indexed model available')
        if self.policy and (set(available) != {name for name in self.encoders if self.settings.weights[name] > 0} or category is not None):
            raise RuntimeError('Calibrated policy requires the complete, unfiltered model pipeline')
        if self.policy and self.settings.patch_weight > 0 and 'patches' not in available.get('dino', {}):
            raise RuntimeError('Calibrated policy requires the complete patch pipeline')
        # Object-index shortlists are primary (only when the query carries a true
        # object); global-index shortlists always contribute the full-image signal.
        # Equal-sized per-model shortlists are combined by configurable reciprocal-rank
        # fusion; the union is bounded by 2*candidates with no full-corpus reads.
        query_has_object = bool(info.get('object_representation'))
        mark = clock()
        shortlists = {f'{name}:global': self.index.search(name, available[name]['context'],
                      self.settings.candidates, category, 'global') for name in available}
        shortlist_weights = {f'{name}:global': self.settings.weights[name] for name in available}
        if query_has_object:
            shortlists.update({f'{name}:object': self.index.search(name, available[name]['global'],
                               self.settings.candidates, category, 'object') for name in available})
            shortlist_weights = {**{f'{name}:object': self.settings.weights[name] * self.settings.object_index_weight
                                    for name in available},
                                 **{f'{name}:global': self.settings.weights[name] * (1 - self.settings.object_index_weight)
                                    for name in available}}
        fused = fuse_ranks(shortlists, shortlist_weights, self.settings.rank_bias)
        ranked = sorted(fused, key=lambda i: (-fused[i], i))
        # Anti-count-bias: a SKU with many photos must not crowd the shortlist,
        # so each SKU contributes at most candidates_per_sku candidates.
        skus = self.index.skus(ranked)
        per_sku = {}
        candidate_ids = []
        for ref_id in ranked:
            sku = skus.get(ref_id, '')
            if per_sku.get(sku, 0) >= self.settings.candidates_per_sku:
                continue
            per_sku[sku] = per_sku.get(sku, 0) + 1
            candidate_ids.append(ref_id)
            if len(candidate_ids) >= self.settings.candidates:
                break
        stage_ms['faiss_ms'] = (clock() - mark) * 1000
        images = []
        # OCR runs at most once, only when candidates and a nonzero secondary budget exist.
        # Structured words carry engine confidences so low-confidence readings
        # contribute little or nothing; legacy string-only extractors keep working
        # with an unscaled score.
        query_attributes, ocr_factor, ocr_tokens = {}, None, []
        if candidate_ids and self.settings.secondary_weight > 0:
            mark = clock()
            selected = BoundingBox(**info['selection_used']).crop(image) if info.get('selection_used') else image
            extractor = getattr(self.ocr, 'extract_words', None)
            if callable(extractor):
                ocr_result = extractor(selected)
                ocr_tokens = sorted(set(ocr_result.tokens))
                query_attributes = parse_attributes(' '.join(ocr_result.texts))
                confidences = [w.confidence for w in ocr_result.words]
                if confidences:
                    ocr_factor = confidence_weight(sum(confidences) / len(confidences),
                                                   self.settings.ocr_min_confidence)
            else:
                query_attributes = parse_attributes(' '.join(self.ocr.extract_text(selected)))
            stage_ms['ocr_ms'] = (clock() - mark) * 1000
        info['ocr_tokens'] = ocr_tokens
        # Local features load only for the bounded shortlist, in one bulk read.
        mark = clock()
        loaded = self.index.references(candidate_ids)
        stage_ms['references_ms'] = (clock() - mark) * 1000
        mark = clock()
        for ref_id in candidate_ids:
            metadata, reference = loaded[ref_id]
            extra = json.loads(metadata.get('extra', '{}'))
            ref_has_object = reference_has_object(extra)
            context_scores = {name: clip_similarity(crops['context'] @ reference[name]['context']) for name, crops in available.items()}
            if query_has_object and ref_has_object:
                # Object vs object is primary; the global pair stays an additional signal.
                object_scores = {name: model_score(crops, reference[name], self.settings.local_weight)
                                 for name, crops in available.items()}
                scores = {name: (1-self.settings.global_weight)*object_scores[name]
                          + self.settings.global_weight*context_scores[name] for name in available}
            elif not query_has_object and not ref_has_object:
                # Both sides are full images; local/detail matching is preserved.
                object_scores = None
                scores = {name: model_score(crops, reference[name], self.settings.local_weight)
                          for name, crops in available.items()}
            else:
                # Mixed representations: object vs full (or full vs object) is never
                # compared directly; the shared global representation is the fallback.
                object_scores = None
                scores = context_scores
            patch_score = None
            if self.settings.patch_weight > 0 and 'dino' in available and 'patches' in available['dino']:
                patch_score = patch_similarity(available['dino']['patches'], reference['dino']['patches'],
                                               self.settings.patch_aggregation, self.settings.patch_top_k,
                                               self.settings.patch_trim)
                scores['dino'] = clip_similarity(
                    (1-self.settings.patch_weight)*scores['dino'] + self.settings.patch_weight*patch_score)
            ocr_score = compatibility(query_attributes, reference_attributes(extra))
            if ocr_score is not None and ocr_factor is not None:
                ocr_score = ocr_score * ocr_factor
            text_score = None
            if self.settings.description_text_weight > 0 and extra.get('description') and 'siglip' in available:
                text_started = clock()
                text_score = clip_similarity(available['siglip']['global'] @ reference['siglip']['description'])
                stage_ms['text_ms'] += (clock() - text_started) * 1000
            components = []
            if text_score is not None: components.append((text_score,self.settings.description_text_weight))
            if ocr_score is not None: components.append((ocr_score,1-self.settings.description_text_weight))
            total = sum(weight for _,weight in components)
            secondary = sum(score*weight for score,weight in components)/total if total else None
            visual = weighted_score(scores, self.settings.weights)
            final = visual if secondary is None else clip_similarity(
                (1-self.settings.secondary_weight)*visual + self.settings.secondary_weight*secondary)
            images.append({'sku': metadata['sku'], 'product_id': metadata['product_id'],
                           'image_id': metadata['image_id'], 'family': metadata.get('family'),
                           'sub_category': metadata.get('sub_category'),
                           'score': final, 'visual_score': visual,
                           'object_score': weighted_score(object_scores, self.settings.weights) if object_scores else None,
                           'global_score': weighted_score(context_scores, self.settings.weights),
                           'ocr_score': ocr_score, 'text_score': text_score,
                           'siglip_score': scores.get('siglip'), 'dino_score': scores.get('dino'),
                           'local_score': patch_score})
        stage_ms['rerank_ms'] = max(0., (clock() - mark) * 1000 - stage_ms['text_ms'])
        all_skus = aggregate_skus(images)
        level, gap = confidence(all_skus, self.settings.high_score, self.settings.high_gap, self.settings.medium_score)
        if self.policy:
            all_skus = [row for row in all_skus if row['score'] >= self.policy['threshold']]
            if not all_skus: level = 'low'
        ambiguity_report = ambiguity(all_skus, self.settings.ambiguity_margin)
        info['models'] = list(available)
        info['evaluation_signature'] = self.evaluation_signature
        # Calibrated only under a validated frozen policy (signature-checked at
        # startup, complete unfiltered pipeline enforced per request); otherwise
        # confidence stays an uncalibrated heuristic, never a probability.
        calibrated = self.policy is not None
        return {'success': True, 'query': info, 'confidence': level, 'score_gap': gap,
                'confidence_calibrated': calibrated,
                'ambiguous': ambiguity_report['ambiguous'], 'ambiguity_reason': ambiguity_report['reason'],
                'ambiguity_alternatives': ambiguity_report['alternatives'],
                'candidate_images': len(candidate_ids),
                'relevance_calibrated': self.policy is not None,
                'latency_ms': (time.perf_counter()-start)*1000,
                'stage_ms': stage_ms,
                'results': [{'rank': rank+1, **item} for rank, item in enumerate(all_skus[:top_k])]}

    @property
    def evaluation_signature(self):
        return {'pipeline': self.signature, 'index_fingerprint': getattr(self.index,'fingerprint',None), 'weights': self.settings.weights,
                'ranking_code': RANKING_CODE,
                'local_weight': self.settings.local_weight, 'global_weight': self.settings.global_weight,
                'patch_weight': self.settings.patch_weight, 'secondary_weight': self.settings.secondary_weight,
                'patch_aggregation': self.settings.patch_aggregation,
                'patch_top_k': self.settings.patch_top_k, 'patch_trim': self.settings.patch_trim,
                'description_text_weight': self.settings.description_text_weight,
                'ocr_enabled': self.settings.ocr_enabled, 'ocr_min_confidence': self.settings.ocr_min_confidence,
                'parser': ATTRIBUTE_VERSION, 'candidates': self.settings.candidates,
                'object_index_weight': self.settings.object_index_weight,
                'candidates_per_sku': self.settings.candidates_per_sku,
                'ambiguity_margin': self.settings.ambiguity_margin,
                # Variant rendering changes reference pixels, so it is signed
                # even though serving compatibility is guarded by content hash.
                'image': {'master_quality': self.settings.master_quality,
                          'catalog_quality': self.settings.catalog_quality,
                          'thumbnail_quality': self.settings.thumbnail_quality,
                          'catalog_side': self.settings.catalog_side,
                          'thumbnail_side': self.settings.thumbnail_side},
                # The frozen policy threshold itself lives in the policy file, not
                # here: including it would make no frozen signature ever match.
                'thresholds': {'high_score': self.settings.high_score,
                               'high_gap': self.settings.high_gap,
                               'medium_score': self.settings.medium_score},
                'rank_bias': self.settings.rank_bias}


def load_encoders(settings: Settings) -> dict[str, ImageEncoder]:
    import torch
    from ..encoders.siglip import SiglipEncoder
    from ..encoders.dino import DinoEncoder
    torch.set_num_threads(settings.cpu_threads)
    device = ('cuda' if torch.cuda.is_available() else 'cpu') if settings.device == 'auto' else settings.device
    if device == 'cuda' and not torch.cuda.is_available():
        raise ValueError('CUDA requested but unavailable')
    logger.info('Device: %s', device)
    encoders = {}
    for name, cls, model, revision in (
        ('siglip', SiglipEncoder, settings.siglip_model, settings.siglip_revision),
        ('dino', DinoEncoder, settings.dino_model, settings.dino_revision),
    ):
        try:
            logger.info('Loading %s: %s', name, model)
            encoders[name] = cls(model, revision, device, settings.batch_size)
        except Exception:
            logger.exception('Failed to load %s; remaining model may serve degraded requests', name)
    if not encoders:
        raise RuntimeError('Both retrieval models failed to load')
    return encoders
