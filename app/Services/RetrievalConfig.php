<?php

namespace App\Services;

/**
 * Fail-fast startup validation for retrieval/image configuration.
 * Invalid values throw during boot instead of causing silent misbehavior
 * (wrong disk writes, unbounded timeouts, unusable thresholds) at runtime.
 */
final class RetrievalConfig
{
    public static function validate(): void
    {
        $fail = fn (string $message) => throw new \RuntimeException('Invalid retrieval configuration: '.$message);
        if (! in_array(config('retrieval.driver'), ['faiss', 'legacy'], true)) {
            $fail('driver must be faiss or legacy');
        }
        foreach (['retrieval.private_disk', 'retrieval.query_temp_disk'] as $key) {
            $disk = config($key);
            if (! is_string($disk) || $disk === 'public' || ! is_array(config('filesystems.disks.'.$disk))) {
                $fail("{$key} must name a configured private disk");
            }
        }
        foreach (['retrieval.connect_timeout' => [1, 30], 'retrieval.read_timeout' => [1, 120]] as $key => [$min, $max]) {
            $value = config($key);
            if (! is_numeric($value) || ! is_finite((float) $value) || $value < $min || $value > $max) {
                $fail("{$key} must be {$min}..{$max}");
            }
        }
        foreach (['product_images.quality', 'product_images.catalog_quality', 'product_images.thumbnail_quality'] as $key) {
            $value = config($key);
            if (! is_numeric($value) || ! is_finite((float) $value) || $value < 75 || $value > 95) {
                $fail("{$key} must be 75..95");
            }
        }
        foreach (['product_images.catalog_side' => [1200, 1600], 'product_images.thumbnail_side' => [300, 500]] as $key => [$min, $max]) {
            $value = config($key);
            if (! is_numeric($value) || ! is_finite((float) $value) || $value < $min || $value > $max) {
                $fail("{$key} must be {$min}..{$max}");
            }
        }
        foreach (['retrieval.reference_min_blur' => 0, 'retrieval.reference_min_side' => 1,
            'retrieval.reference_min_crop_pixels' => 1, 'retrieval.reference_min_std' => 0,
            'retrieval.reference_max_dhash_distance' => 0] as $key => $min) {
            $value = config($key);
            if (! is_numeric($value) || ! is_finite((float) $value) || $value < $min) {
                $fail("{$key} must be >= {$min}");
            }
        }
    }
}
