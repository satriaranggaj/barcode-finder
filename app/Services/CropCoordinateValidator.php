<?php

namespace App\Services;

class CropCoordinateValidator
{
    /**
     * Validate normalized crop coordinates (0..1 range).
     */
    public static function validate(array $crop): bool
    {
        if (!isset($crop['x'], $crop['y'], $crop['width'], $crop['height'])) {
            return false;
        }

        $x = (float) $crop['x'];
        $y = (float) $crop['y'];
        $width = (float) $crop['width'];
        $height = (float) $crop['height'];

        // All values must be numeric and in valid ranges
        if ($x < 0 || $x > 1 || $y < 0 || $y > 1) {
            return false;
        }

        if ($width <= 0 || $width > 1 || $height <= 0 || $height > 1) {
            return false;
        }

        // Crop area must not exceed image bounds
        if (($x + $width) > 1 || ($y + $height) > 1) {
            return false;
        }

        // Minimum size check (prevent tiny selections)
        $minSize = 0.05; // 5% of image dimension
        if ($width < $minSize || $height < $minSize) {
            return false;
        }

        return true;
    }

    /**
     * Normalize raw pixel coordinates to 0..1 range.
     */
    public static function normalize(int $x, int $y, int $width, int $height, int $imageWidth, int $imageHeight): array
    {
        if ($imageWidth <= 0 || $imageHeight <= 0) {
            throw new \InvalidArgumentException('Image dimensions must be positive.');
        }

        return [
            'x' => round($x / $imageWidth, 4),
            'y' => round($y / $imageHeight, 4),
            'width' => round($width / $imageWidth, 4),
            'height' => round($height / $imageHeight, 4),
        ];
    }

    /**
     * Convert normalized coordinates back to pixel coordinates.
     */
    public static function denormalize(array $crop, int $imageWidth, int $imageHeight): array
    {
        return [
            'x' => (int) round($crop['x'] * $imageWidth),
            'y' => (int) round($crop['y'] * $imageHeight),
            'width' => (int) round($crop['width'] * $imageWidth),
            'height' => (int) round($crop['height'] * $imageHeight),
        ];
    }
}
