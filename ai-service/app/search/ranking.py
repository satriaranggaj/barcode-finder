"""Configurable global/local reranking and multi-reference SKU aggregation."""
import numpy as np


def model_score(query: dict, reference: dict, local_weight: float) -> float:
    global_score = float(query['global'] @ reference['global'])
    if local_weight <= 0:
        # Global-only ablation: skip the local match matrix entirely.
        return float(np.clip(global_score, -1, 1))
    q = np.stack([v for k, v in query.items() if k not in ('global', 'context', 'patches', 'description')])
    r = np.stack([v for k, v in reference.items() if k not in ('global', 'context', 'patches', 'description')])
    similarities = q @ r.T
    # Symmetric best local matches tolerate viewpoint changes without assigning tip labels.
    local_score = float((similarities.max(axis=1).mean() + similarities.max(axis=0).mean()) / 2)
    if local_weight >= 1:
        return float(np.clip(local_score, -1, 1))
    return float(np.clip((1-local_weight)*global_score + local_weight*local_score, -1, 1))


def _best_matches(query, reference, query_mask, reference_mask):
    q = query if query_mask is None else query[query_mask]
    r = reference if reference_mask is None else reference[reference_mask]
    if min(len(q), len(r)) < 4:
        raise ValueError('At least four normalized patches are required')
    similarities = q @ r.T
    # One direction per patch: best correspondence, not a single global winner.
    return similarities.max(axis=1), similarities.max(axis=0), len(q), len(r)


def _aggregate(values, aggregation, top_k, trim) -> float:
    if aggregation == 'mean':
        return float(values.mean())
    if aggregation == 'median':
        return float(np.median(values))
    if aggregation == 'trimmed_mean':
        if not 0 <= trim < .5:
            raise ValueError('Patch trim must be 0 <= trim < 0.5')
        drop = int(trim * len(values))
        if 2 * drop >= len(values):
            raise ValueError('Patch trim too large for patch evidence')
        return float(np.sort(values)[drop:len(values) - drop].mean())
    if aggregation == 'top_k':
        if top_k < 1:
            raise ValueError('Patch top_k must be positive')
        return float(np.sort(values)[-min(top_k, len(values)):].mean())
    raise ValueError(f'Unknown patch aggregation: {aggregation}')


def patch_correspondence_scores(query, reference, aggregation: str = 'top_k',
                                top_k: int = 16, trim: float = 0.1,
                                query_mask=None, reference_mask=None) -> dict:
    """Per-direction correspondence aggregates plus the symmetric similarity.

    Symmetry holds by construction: both directions use the same aggregation and
    the symmetric value averages them. Masked-out patches (padding/background)
    never contribute. Scores are similarities, never probabilities.
    """
    masks = [np.asarray(mask) if mask is not None else None for mask in (query_mask, reference_mask)]
    for name, mask, source in zip(('query', 'reference'), masks, (query, reference)):
        if mask is not None and (mask.ndim != 1 or mask.dtype != bool or len(mask) != len(source)):
            raise ValueError(f'Invalid {name} patch mask')
    q2r, r2q, nq, nr = _best_matches(query, reference, masks[0], masks[1])
    # The smaller side bounds the correspondence evidence on both directions;
    # a reference with many extra patches cannot win by count.
    shared_k = min(top_k, nq, nr)
    aggregate_q = _aggregate(q2r, aggregation, shared_k, trim)
    aggregate_r = _aggregate(r2q, aggregation, shared_k, trim)
    return {'query_to_reference': aggregate_q, 'reference_to_query': aggregate_r,
            'symmetric': float(np.clip((aggregate_q + aggregate_r) / 2, -1, 1)),
            'query_patches': nq, 'reference_patches': nr}


def patch_similarity(query, reference, aggregation: str = 'top_k',
                     top_k: int = 16, trim: float = 0.1,
                     query_mask=None, reference_mask=None) -> float:
    """Robust symmetric local similarity; never a single winning patch."""
    return patch_correspondence_scores(query, reference, aggregation, top_k, trim,
                                       query_mask, reference_mask)['symmetric']


def weighted_score(scores: dict[str, float], weights: dict[str, float]) -> float:
    total = sum(weights[name] for name in scores)
    if total <= 0:
        raise ValueError('No available model with a positive weight')
    # Weighted means of in-range similarities stay in range mathematically,
    # but float rounding can still land one ulp outside; the API contract is
    # [-1, 1], so clamp instead of trusting arithmetic.
    return float(np.clip(sum(value * weights[name] for name, value in scores.items()) / total, -1, 1))


def fuse_ranks(shortlists: dict[str, list[tuple[int, float]]], weights: dict[str, float],
               rank_bias: float) -> dict[int, float]:
    """Reciprocal-rank fusion of per-model shortlists.

    Fused score rises with model weight and with better (smaller) rank; larger
    rank_bias flattens rank differences. rank_bias=0 gives classic 1/(rank+1).
    """
    fused: dict[int, float] = {}
    for name, ranked in shortlists.items():
        for rank, (reference_id, _) in enumerate(ranked):
            fused[reference_id] = fused.get(reference_id, 0.0) + weights[name] / (rank_bias + rank + 1)
    return fused


def aggregate_skus(images: list[dict]) -> list[dict]:
    """Max reference score avoids rewarding SKUs solely for having more photos.

    The winning row keeps the best-matching reference view (image_id/score);
    matched_image_ids lists every shortlisted view of that SKU, ordered by
    score then image_id, as deterministic match evidence. Counts never raise
    the score: extra or irrelevant views cannot inflate it.
    """
    grouped: dict[str, dict] = {}
    for image in sorted(images, key=lambda r: (-r['score'], r['image_id'])):
        sku = image['sku']
        if sku not in grouped:
            grouped[sku] = {**image, 'matched_images': 0, 'matched_image_ids': []}
        grouped[sku]['matched_images'] += 1
        grouped[sku]['matched_image_ids'].append(image['image_id'])
    return sorted(grouped.values(), key=lambda r: (-r['score'], r['sku']))


def ambiguity(results: list[dict], margin: float, max_alternatives: int = 5) -> dict:
    """Flag near-tied Top-K alternatives without pretending certainty.

    Ambiguous when two or more SKUs score within margin of the leader: the
    photo may lack the distinguishing detail (e.g. a size marking). When the
    tied alternatives share a family, the reason names it so staff know which
    variant to check. A display heuristic only, never a probability, and
    never a calibrated no-match gate.
    """
    if margin < 0:
        raise ValueError('Ambiguity margin must be nonnegative')
    if not results:
        return {'ambiguous': False, 'reason': None, 'alternatives': []}
    top = results[0]['score']
    tied = [row for row in results[:max_alternatives] if top - row['score'] <= margin]
    alternatives = [{'sku': row['sku'], 'score': row['score'],
                     'family': row.get('family')} for row in tied]
    if len(tied) < 2:
        return {'ambiguous': False, 'reason': None, 'alternatives': alternatives}
    families = {row.get('family') for row in tied} - {None}
    reason = 'same_family_variants' if len(families) == 1 else 'near_tie'
    return {'ambiguous': True, 'reason': reason, 'alternatives': alternatives}


def confidence(results: list[dict], high_score: float, high_gap: float, medium_score: float) -> tuple[str, float | None]:
    """Uncalibrated heuristic confidence; scores are similarities, not probabilities.

    Semantics of the /search response fields:
    - score: cosine-style similarity in [-1, 1]; higher means closer, nothing more.
    - score_gap: top1 minus top2 SKU score, None when fewer than two SKUs match.
    - confidence: 'high' only when the top score clears high_score AND the gap
      clears high_gap (a lone high score with no runner-up margin is 'medium'
      at best); 'medium' above medium_score; else 'low', including empty results.
    - confidence_calibrated: True only under a validated frozen relevance policy
      (signature-checked, full pipeline); otherwise False and the level above
      must be read as a heuristic. A calibrated policy additionally filters
      below-threshold candidates, which is the only no-match gate: without it,
      ambiguous or poor queries still return Top-K with low confidence rather
      than a forced answer.
    """
    if not results:
        return 'low', None
    first = results[0]['score']
    gap = first - results[1]['score'] if len(results) > 1 else None
    # A singleton shortlist is not evidence of a large margin.
    if gap is not None and first >= high_score and gap >= high_gap:
        return 'high', gap
    return ('medium' if first >= medium_score else 'low'), gap
