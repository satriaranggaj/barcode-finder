<?php

namespace App\Services;

/**
 * Minimum quality gate for candidate confirmed_search references.
 *
 * Judges reference fitness only (decodable, large enough, non-blank,
 * non-extreme-blur) — never photo aesthetics. Every threshold is
 * configurable and every rejection carries machine-readable reasons plus
 * the component metrics, so borderline store photos are flagged, not
 * silently dropped, and noisy-but-usable captures still pass.
 */
final class ReferenceQuality
{
    /**
     * @return array{eligible: bool, reasons: list<string>, metrics: array{blur: ?float, min_side: ?int, crop_pixels: ?int, std: ?float}}
     */
    public static function evaluate(array $quality): array
    {
        $metrics = [
            'blur' => isset($quality['blur']) && is_numeric($quality['blur']) ? (float) $quality['blur'] : null,
            'min_side' => isset($quality['min_side']) && is_numeric($quality['min_side']) ? (int) $quality['min_side'] : null,
            'crop_pixels' => isset($quality['crop_pixels']) && is_numeric($quality['crop_pixels']) ? (int) $quality['crop_pixels'] : null,
            'std' => isset($quality['gray_std']) && is_numeric($quality['gray_std']) ? (float) $quality['gray_std'] : null,
        ];
        $reasons = [];
        if ($metrics['blur'] === null || ! is_finite($metrics['blur'])) {
            $reasons[] = 'missing_blur';
        } elseif ($metrics['blur'] < (float) config('retrieval.reference_min_blur')) {
            $reasons[] = 'low_blur';
        }
        if ($metrics['min_side'] === null) {
            $reasons[] = 'missing_size';
        } elseif ($metrics['min_side'] < (int) config('retrieval.reference_min_side')) {
            $reasons[] = 'too_small';
        }
        // Newer metrics are optional so rows staged before they existed stay assessable.
        if ($metrics['crop_pixels'] !== null && $metrics['crop_pixels'] < (int) config('retrieval.reference_min_crop_pixels')) {
            $reasons[] = 'object_too_small';
        }
        if ($metrics['std'] !== null && $metrics['std'] < (float) config('retrieval.reference_min_std')) {
            $reasons[] = 'near_blank';
        }

        return ['eligible' => $reasons === [], 'reasons' => $reasons, 'metrics' => $metrics];
    }
}
