"""Shared orchestration for API, offline indexing and evaluation."""
import logging
import time
import faiss
from PIL import Image
from ..config import Settings
from ..encoders.base import ImageEncoder, normalize
from ..preprocessing.pipeline import Preprocessor, pad_square, VERSION
from ..preprocessing.crop import multi_crop
from ..features.ocr import DisabledOCR, TextExtractor
from .faiss_index import FaissIndexManager
from .ranking import model_score, weighted_score, aggregate_skus, confidence

logger = logging.getLogger(__name__)


class RetrievalService:
    def __init__(self, settings: Settings, encoders: dict[str, ImageEncoder],
                 index: FaissIndexManager | None = None, ocr: TextExtractor | None = None):
        self.settings, self.encoders, self.index = settings, encoders, index
        self.preprocessor = Preprocessor(settings.max_side)
        self.ocr = ocr or DisabledOCR()
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
                'preprocessing': {'version': VERSION, 'max_side': self.settings.max_side,
                                  'reference_mode': self.settings.preprocessing_mode}}

    def embed(self, image: Image.Image, mode: str) -> tuple[dict, dict]:
        prepared = self.preprocessor.prepare(image, mode)
        crops = multi_crop(prepared.image)
        images = [pad_square(crop) for crop in crops.values()]
        representations = {}
        for name, encoder in self.encoders.items():
            try:
                vectors = normalize(encoder.encode_images(images))
                if vectors.ndim != 2 or vectors.shape[0] != len(images):
                    raise ValueError('Encoder returned an invalid crop batch')
                representations[name] = dict(zip(crops, vectors))
            except Exception:
                logger.exception('Encoder unavailable: %s', name)
        if not representations:
            raise RuntimeError('No encoder available')
        return representations, {'preprocessing': prepared.mode, 'reason': prepared.reason,
                                 'requested_preprocessing': mode, 'models': list(representations)}

    def search(self, image: Image.Image, top_k: int = 5, category: str | None = None,
               mode: str = 'object') -> dict:
        if not 1 <= top_k <= 50:
            raise ValueError('top_k must be 1..50')
        if self.index is None:
            raise RuntimeError('Index missing; build index first')
        start = time.perf_counter()
        faiss.omp_set_num_threads(self.settings.cpu_threads)
        query, info = self.embed(image, mode)
        available = {name: crops for name, crops in query.items()
                     if name in self.index.signature['models'] and self.settings.weights[name] > 0}
        if not available:
            raise RuntimeError('No compatible indexed model available')
        # Equal-sized per-model shortlists, combined by rank (cosine scales differ).
        # The union is bounded by 2*candidates; no full-corpus vector comparisons.
        fused = {}
        for name, crops in available.items():
            for rank, (ref_id, _) in enumerate(self.index.search(name, crops['global'], self.settings.candidates, category)):
                fused[ref_id] = fused.get(ref_id, 0) + self.settings.weights[name] / (60 + rank + 1)
        candidate_ids = sorted(fused, key=lambda i: (-fused[i], i))[:self.settings.candidates]
        images = []
        for ref_id in candidate_ids:
            metadata, reference = self.index.reference(ref_id)
            scores = {name: model_score(crops, reference[name], self.settings.local_weight)
                      for name, crops in available.items()}
            images.append({'sku': metadata['sku'], 'product_id': metadata['product_id'],
                           'image_id': metadata['image_id'], 'score': weighted_score(scores, self.settings.weights),
                           'siglip_score': scores.get('siglip'), 'dino_score': scores.get('dino')})
        all_skus = aggregate_skus(images)
        level, gap = confidence(all_skus, self.settings.high_score, self.settings.high_gap, self.settings.medium_score)
        info['models'] = list(available)
        return {'success': True, 'query': info, 'confidence': level, 'score_gap': gap,
                'confidence_calibrated': False, 'candidate_images': len(candidate_ids),
                'latency_ms': (time.perf_counter()-start)*1000,
                'results': [{'rank': rank+1, **item} for rank, item in enumerate(all_skus[:top_k])]}


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
