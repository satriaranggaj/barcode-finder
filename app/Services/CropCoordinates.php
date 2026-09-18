<?php

namespace App\Services;

use Illuminate\Validation\ValidationException;

/**
 * Single source of truth for the crop coordinate contract shared with ai-service
 * (ai-service/app/preprocessing/selection.py). Coordinates are normalized 0..1
 * fractions of the orientation-normalized image (EXIF orientation applied), so
 * query and reference flows always use the same convention.
 */
class CropCoordinates
{
    /** Sum tolerance: absorbs 4-decimal coordinate rounding plus float error. */
    public const TOLERANCE = .0001;

    /** Minimum normalized crop side; smaller crops are rejected, never silently accepted. */
    public const MIN_EXTENT = .01;

    public const SELECTION_MODES = ['auto', 'manual', 'full'];

    public static function fromJson(?string $json): ?array
    {
        if ($json === null || $json === '') {
            return null;
        }
        $crop = json_decode($json, true);
        if (json_last_error() !== JSON_ERROR_NONE || ($crop !== null && ! is_array($crop))) {
            throw ValidationException::withMessages(['crop' => 'Koordinat crop tidak valid.']);
        }

        return self::validate($crop);
    }

    public static function validate(?array $crop): ?array
    {
        if ($crop === null || $crop === []) {
            return null;
        }

        $result = [];
        foreach (['x', 'y', 'width', 'height'] as $key) {
            $value = $crop[$key] ?? null;
            if (! is_numeric($value) || ! is_finite((float) $value)) {
                throw ValidationException::withMessages(['crop' => "Koordinat crop {$key} harus berupa angka."]);
            }
            $result[$key] = (float) $value;
        }
        foreach (['x', 'y'] as $key) {
            if ($result[$key] < 0 || $result[$key] > 1) {
                throw ValidationException::withMessages(['crop' => "Crop {$key} harus berada di antara 0 dan 1."]);
            }
        }
        foreach (['width', 'height'] as $key) {
            if ($result[$key] < self::MIN_EXTENT || $result[$key] > 1) {
                throw ValidationException::withMessages(['crop' => "Crop {$key} harus berada di antara ".self::MIN_EXTENT.' dan 1.']);
            }
        }
        if (1 + self::TOLERANCE < $result['x'] + $result['width']) {
            throw ValidationException::withMessages(['crop' => 'Crop melebihi tepi kanan foto (x + width harus <= 1).']);
        }
        if (1 + self::TOLERANCE < $result['y'] + $result['height']) {
            throw ValidationException::withMessages(['crop' => 'Crop melebihi tepi bawah foto (y + height harus <= 1).']);
        }

        return $result;
    }

    /**
     * Derive the wire selection_mode from the Laravel selection_source.
     */
    public static function selectionMode(?string $source, ?array $crop): string
    {
        if ($source !== null) {
            return $source;
        }

        return $crop ? 'manual' : 'auto';
    }

    /**
     * Normalize raw pixel coordinates to 0..1 range (4-decimal rounding).
     */
    public static function normalize(int $x, int $y, int $width, int $height, int $imageWidth, int $imageHeight): array
    {
        if ($imageWidth <= 0 || $imageHeight <= 0) {
            throw new \InvalidArgumentException('Image dimensions must be positive.');
        }
        if ($width <= 0 || $height <= 0) {
            throw new \InvalidArgumentException('Crop dimensions must be positive.');
        }

        return [
            'x' => round($x / $imageWidth, 4),
            'y' => round($y / $imageHeight, 4),
            'width' => round($width / $imageWidth, 4),
            'height' => round($height / $imageHeight, 4),
        ];
    }

    /**
     * Convert normalized coordinates back to pixel coordinates using the same
     * floor/ceil containment rule as BoundingBox.crop() in ai-service: the pixel
     * box always covers the normalized box and never has zero size.
     */
    public static function denormalize(array $crop, int $imageWidth, int $imageHeight): array
    {
        if ($imageWidth <= 0 || $imageHeight <= 0) {
            throw new \InvalidArgumentException('Image dimensions must be positive.');
        }

        $left = (int) floor($crop['x'] * $imageWidth);
        $top = (int) floor($crop['y'] * $imageHeight);
        $right = min($imageWidth, (int) ceil(($crop['x'] + $crop['width']) * $imageWidth));
        $bottom = min($imageHeight, (int) ceil(($crop['y'] + $crop['height']) * $imageHeight));

        return [
            'x' => $left,
            'y' => $top,
            'width' => max(1, $right - $left),
            'height' => max(1, $bottom - $top),
        ];
    }
}
