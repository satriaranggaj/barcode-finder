"""Configurable global/local reranking and multi-reference SKU aggregation."""
import numpy as np


def model_score(query: dict, reference: dict, local_weight: float) -> float:
    global_score = float(query['global'] @ reference['global'])
    q = np.stack([v for k, v in query.items() if k != 'global'])
    r = np.stack([v for k, v in reference.items() if k != 'global'])
    similarities = q @ r.T
    # Symmetric best local matches tolerate viewpoint changes without assigning tip labels.
    local_score = float((similarities.max(axis=1).mean() + similarities.max(axis=0).mean()) / 2)
    return float(np.clip((1-local_weight)*global_score + local_weight*local_score, -1, 1))


def weighted_score(scores: dict[str, float], weights: dict[str, float]) -> float:
    total = sum(weights[name] for name in scores)
    if total <= 0:
        raise ValueError('No available model with a positive weight')
    return sum(value * weights[name] for name, value in scores.items()) / total


def aggregate_skus(images: list[dict]) -> list[dict]:
    """Max reference score avoids rewarding SKUs solely for having more photos."""
    grouped: dict[str, dict] = {}
    for image in sorted(images, key=lambda r: (-r['score'], r['image_id'])):
        sku = image['sku']
        if sku not in grouped:
            grouped[sku] = {**image, 'matched_images': 0}
        grouped[sku]['matched_images'] += 1
    return sorted(grouped.values(), key=lambda r: (-r['score'], r['sku']))


def confidence(results: list[dict], high_score: float, high_gap: float, medium_score: float) -> tuple[str, float | None]:
    if not results:
        return 'low', None
    first = results[0]['score']
    gap = first - results[1]['score'] if len(results) > 1 else None
    # A singleton shortlist is not evidence of a large margin.
    if gap is not None and first >= high_score and gap >= high_gap:
        return 'high', gap
    return ('medium' if first >= medium_score else 'low'), gap
