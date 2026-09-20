<?php

namespace App\Services;

use Illuminate\Validation\ValidationException;

/**
 * Normalizes staged search evidence into verified-feedback columns.
 *
 * Only staff-confirmed rows reach the database (a prediction is never ground
 * truth), and what reaches it is a bounded, whitelisted snapshot: top
 * candidates with component scores, confidence fields, and selection source.
 * Full embedding vectors are rejected fail-closed; unknown keys are dropped
 * so future ai-service fields cannot leak into storage.
 */
final class FeedbackPayload
{
    /** Hard cap on stored candidates; RetrievalClient already caps results at 5. */
    public const MAX_CANDIDATES = 8;

    /** Upper bound for any single JSON snapshot written to search_feedback. */
    public const MAX_JSON_BYTES = 32768;

    public const CONFIDENCES = ['low', 'medium', 'high'];

    /** Candidate scalar fields persisted as match evidence (scores, never vectors). */
    private const SCORE_KEYS = [
        'score', 'visual_score', 'siglip_score', 'dino_score', 'local_score',
        'text_score', 'ocr_score', 'global_score', 'object_score', 'tip_score',
        'proportion_score', 'global_fallback_score',
    ];

    /** Whitelisted FastAPI query info keys for the sanitized evidence snapshot. */
    private const QUERY_KEYS = [
        'preprocessing', 'reason', 'requested_preprocessing', 'models',
        'selection_used', 'object_representation',
    ];

    /**
     * @return array{candidates: array, confidence: ?string, score_gap: ?float, selection_source: ?string}
     */
    public static function verifiedAttributes(array $pending): array
    {
        $results = $pending['results'] ?? [];
        if (! is_array($results)) {
            throw ValidationException::withMessages(['token' => 'Data kandidat pencarian tidak valid.']);
        }
        if (count($results) > self::MAX_CANDIDATES) {
            throw ValidationException::withMessages(['token' => 'Jumlah kandidat melebihi batas penyimpanan.']);
        }
        $candidates = [];
        foreach (array_values($results) as $position => $row) {
            $candidates[] = self::candidate($row, $position);
        }
        $confidence = $pending['confidence'] ?? null;
        if ($confidence !== null && ! in_array($confidence, self::CONFIDENCES, true)) {
            throw ValidationException::withMessages(['token' => 'Nilai confidence tidak valid.']);
        }
        $scoreGap = $pending['score_gap'] ?? null;
        if ($scoreGap !== null && (! is_numeric($scoreGap) || ! is_finite((float) $scoreGap))) {
            throw ValidationException::withMessages(['token' => 'Nilai score gap tidak valid.']);
        }
        $source = $pending['selection_source'] ?? null;
        if ($source !== null && ! in_array($source, CropCoordinates::SELECTION_MODES, true)) {
            throw ValidationException::withMessages(['token' => 'Selection source tidak valid.']);
        }
        $payload = [
            'candidates' => $candidates,
            'confidence' => $confidence,
            'score_gap' => $scoreGap === null ? null : (float) $scoreGap,
            'selection_source' => $source,
        ];
        self::assertJsonBudget($payload);

        return $payload;
    }

    /**
     * Bounded legacy snapshot for the evidence column. Keeps capture_group
     * (read by training/reference exporters) plus quality metrics and a
     * whitelisted query summary. Never contains image bytes or vectors.
     */
    public static function sanitizedEvidence(array $pending, array $reasons = []): array
    {
        $quality = $pending['evidence'] ?? [];
        $quality = is_array($quality) ? $quality : [];
        $query = $pending['query'] ?? [];
        $query = is_array($query) ? array_intersect_key($query, array_flip(self::QUERY_KEYS)) : [];
        $group = $pending['capture_group'] ?? null;
        $cleanReasons = [];
        foreach (array_slice($reasons, 0, 8) as $reason) {
            if (is_string($reason) && $reason !== '' && strlen($reason) <= 64) {
                $cleanReasons[] = $reason;
            }
        }
        $snapshot = [
            'capture_group' => is_string($group) && $group !== '' ? substr($group, 0, 128) : 'unknown-session',
            'quality' => [
                'photo_hash' => $quality['photo_hash'] ?? null,
                'dhash' => $quality['dhash'] ?? null,
                'blur' => isset($quality['blur']) && is_numeric($quality['blur']) ? (float) $quality['blur'] : null,
                'min_side' => isset($quality['min_side']) && is_numeric($quality['min_side']) ? (int) $quality['min_side'] : null,
                'crop_pixels' => isset($quality['crop_pixels']) && is_numeric($quality['crop_pixels']) ? (int) $quality['crop_pixels'] : null,
                'std' => isset($quality['gray_std']) && is_numeric($quality['gray_std']) ? (float) $quality['gray_std'] : null,
                'reasons' => $cleanReasons,
            ],
            'query' => $query,
            // A digest preserves reproducible pipeline provenance without
            // persisting a large nested response or embedding-like payloads.
            'pipeline_signature_hash' => isset($pending['query']['evaluation_signature'])
                ? hash('sha256', json_encode($pending['query']['evaluation_signature'], JSON_THROW_ON_ERROR)) : null,
        ];
        self::assertJsonBudget($snapshot);

        return $snapshot;
    }

    private static function candidate(mixed $row, int $position): array
    {
        if (! is_array($row)) {
            throw ValidationException::withMessages(['token' => 'Data kandidat pencarian tidak valid.']);
        }
        self::assertNoEmbeddingKeys($row);
        $sku = $row['sku'] ?? null;
        if (! is_string($sku) || $sku === '' || strlen($sku) > 255) {
            throw ValidationException::withMessages(['token' => 'SKU kandidat tidak valid.']);
        }
        $rank = $row['rank'] ?? $position + 1;
        if (! is_int($rank) && ! (is_numeric($rank) && (int) $rank == $row['rank'])) {
            throw ValidationException::withMessages(['token' => 'Rank kandidat tidak valid.']);
        }
        $rank = (int) $rank;
        if ($rank < 1) {
            throw ValidationException::withMessages(['token' => 'Rank kandidat tidak valid.']);
        }
        $normalized = ['rank' => $rank, 'sku' => $sku];
        $imageId = $row['image_id'] ?? null;
        $normalized['image_id'] = is_string($imageId) && $imageId !== '' && strlen($imageId) <= 512 ? $imageId : null;
        $productId = $row['product_id'] ?? null;
        $normalized['product_id'] = is_int($productId) || (is_string($productId) && $productId !== '' && strlen($productId) <= 64) ? $productId : null;
        foreach (self::SCORE_KEYS as $key) {
            $value = $row[$key] ?? null;
            if ($value === null) {
                $normalized[$key] = null;

                continue;
            }
            if (! is_numeric($value) || ! is_finite((float) $value) || abs((float) $value) > 1.000001) {
                throw ValidationException::withMessages(['token' => "Skor kandidat {$key} tidak valid."]);
            }
            $normalized[$key] = (float) $value;
        }
        $matched = $row['matched_images'] ?? null;
        $normalized['matched_images'] = is_int($matched) && $matched >= 0 ? $matched
            : (is_numeric($matched) && (int) $matched == $matched && (int) $matched >= 0 ? (int) $matched : null);

        return $normalized;
    }

    private static function assertNoEmbeddingKeys(array $row): void
    {
        foreach (array_keys($row) as $key) {
            if (is_string($key) && preg_match('/embedding|vector/i', $key)) {
                throw ValidationException::withMessages(['token' => 'Data kandidat memuat embedding; ditolak.']);
            }
        }
    }

    private static function assertJsonBudget(array $payload): void
    {
        $encoded = json_encode($payload);
        if ($encoded === false || strlen($encoded) > self::MAX_JSON_BYTES) {
            throw ValidationException::withMessages(['token' => 'Ukuran data evidence melebihi batas penyimpanan.']);
        }
    }
}
