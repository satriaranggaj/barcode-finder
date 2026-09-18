<?php

namespace Tests\Unit;

use App\Services\CropCoordinates;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class CropCoordinatesTest extends TestCase
{
    public function test_valid_crop_is_accepted(): void
    {
        $crop = ['x' => .1, 'y' => .2, 'width' => .5, 'height' => .6];
        $this->assertSame($crop, CropCoordinates::validate($crop));
    }

    public function test_numeric_strings_are_cast_to_floats(): void
    {
        $this->assertSame(['x' => 0.0, 'y' => 0.0, 'width' => .5, 'height' => .5],
            CropCoordinates::validate(['x' => '0', 'y' => '0', 'width' => '0.5', 'height' => '0.5']));
    }

    public function test_boundary_full_image_crop_is_accepted(): void
    {
        $this->assertSame(['x' => 0.0, 'y' => 0.0, 'width' => 1.0, 'height' => 1.0],
            CropCoordinates::validate(['x' => 0, 'y' => 0, 'width' => 1, 'height' => 1]));
    }

    public function test_no_crop_input_is_null(): void
    {
        $this->assertNull(CropCoordinates::validate(null));
        $this->assertNull(CropCoordinates::validate([]));
        $this->assertNull(CropCoordinates::fromJson(null));
        $this->assertNull(CropCoordinates::fromJson('null'));
    }

    public function test_invalid_json_raises_validation_error(): void
    {
        $this->expectException(ValidationException::class);
        CropCoordinates::fromJson('not-json{{');
    }

    public function test_sum_tolerance_accepts_four_decimal_rounding(): void
    {
        CropCoordinates::validate(['x' => .9, 'y' => .9, 'width' => .10005, 'height' => .10005]);
        $this->assertTrue(true);
    }

    public function test_negative_coordinates_are_rejected(): void
    {
        $this->assertCropError(['x' => -.01, 'y' => 0, 'width' => .5, 'height' => .5]);
        $this->assertCropError(['x' => 0, 'y' => -.01, 'width' => .5, 'height' => .5]);
        $this->assertCropError(['x' => 0, 'y' => 0, 'width' => -.5, 'height' => .5]);
        $this->assertCropError(['x' => 0, 'y' => 0, 'width' => .5, 'height' => -.5]);
    }

    public function test_coordinates_above_one_are_rejected(): void
    {
        $this->assertCropError(['x' => 1.001, 'y' => 0, 'width' => .01, 'height' => .5]);
        $this->assertCropError(['x' => 0, 'y' => 1.001, 'width' => .5, 'height' => .01]);
        $this->assertCropError(['x' => 0, 'y' => 0, 'width' => 1.001, 'height' => .5]);
        $this->assertCropError(['x' => 0, 'y' => 0, 'width' => .5, 'height' => 1.001]);
    }

    public function test_overflow_beyond_tolerance_is_rejected(): void
    {
        $this->assertCropError(['x' => .9, 'y' => 0, 'width' => .1002, 'height' => 1]);
        $this->assertCropError(['x' => 0, 'y' => .9, 'width' => 1, 'height' => .1002]);
    }

    public function test_tiny_crop_below_min_extent_is_rejected(): void
    {
        $this->assertCropError(['x' => 0, 'y' => 0, 'width' => .005, 'height' => .5]);
        $this->assertCropError(['x' => 0, 'y' => 0, 'width' => .5, 'height' => .005]);
    }

    public function test_zero_missing_and_non_numeric_values_are_rejected(): void
    {
        $this->assertCropError(['x' => 0, 'y' => 0, 'width' => 0, 'height' => .5]);
        $this->assertCropError(['x' => 0, 'y' => 0, 'width' => .5, 'height' => 'abc']);
        $this->assertCropError(['x' => NAN, 'y' => 0, 'width' => .5, 'height' => .5]);
        $this->assertCropError(['x' => INF, 'y' => 0, 'width' => .5, 'height' => .5]);
        $this->assertCropError(['x' => 0, 'y' => 0, 'width' => .5]);
    }

    public function test_selection_mode_is_derived(): void
    {
        $crop = ['x' => 0, 'y' => 0, 'width' => 1, 'height' => 1];
        $this->assertSame('manual', CropCoordinates::selectionMode(null, $crop));
        $this->assertSame('auto', CropCoordinates::selectionMode(null, null));
        $this->assertSame('full', CropCoordinates::selectionMode('full', null));
        $this->assertSame('manual', CropCoordinates::selectionMode('manual', $crop));
    }

    public function test_denormalize_matches_pixel_containment_rule(): void
    {
        // Same floor/ceil containment rule as BoundingBox.crop() in ai-service.
        $this->assertSame(['x' => 0, 'y' => 0, 'width' => 34, 'height' => 34],
            CropCoordinates::denormalize(['x' => 0, 'y' => 0, 'width' => .333, 'height' => .333], 100, 100));
        $this->assertSame(['x' => 10, 'y' => 40, 'width' => 50, 'height' => 100],
            CropCoordinates::denormalize(['x' => .1, 'y' => .2, 'width' => .5, 'height' => .5], 100, 200));
    }

    public function test_denormalize_landscape_and_portrait(): void
    {
        $this->assertSame(['x' => 20, 'y' => 20, 'width' => 100, 'height' => 50],
            CropCoordinates::denormalize(['x' => .1, 'y' => .2, 'width' => .5, 'height' => .5], 200, 100));
        $this->assertSame(['x' => 10, 'y' => 40, 'width' => 50, 'height' => 100],
            CropCoordinates::denormalize(['x' => .1, 'y' => .2, 'width' => .5, 'height' => .5], 100, 200));
    }

    public function test_denormalize_never_returns_zero_size(): void
    {
        $rect = CropCoordinates::denormalize(['x' => 0, 'y' => 0, 'width' => .01, 'height' => .01], 10, 10);
        $this->assertGreaterThanOrEqual(1, $rect['width']);
        $this->assertGreaterThanOrEqual(1, $rect['height']);
    }

    public function test_normalize_denormalize_round_trip_stays_within_two_pixels(): void
    {
        $normalized = CropCoordinates::normalize(53, 77, 311, 199, 640, 480);
        $rect = CropCoordinates::denormalize($normalized, 640, 480);
        $again = CropCoordinates::normalize($rect['x'], $rect['y'], $rect['width'], $rect['height'], 640, 480);
        $bound = 2 / 480 + .0001;
        foreach (['x', 'y', 'width', 'height'] as $key) {
            $this->assertLessThanOrEqual($bound, abs($normalized[$key] - $again[$key]), $key);
        }
    }

    public function test_normalize_rejects_non_positive_dimensions(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        CropCoordinates::normalize(0, 0, 10, 10, 0, 100);
    }

    public function test_normalize_rejects_zero_crop_size(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        CropCoordinates::normalize(0, 0, 0, 10, 100, 100);
    }

    private function assertCropError(array $crop): void
    {
        try {
            CropCoordinates::validate($crop);
            $this->fail('Expected ValidationException for crop '.var_export($crop, true));
        } catch (ValidationException $exception) {
            $this->assertNotEmpty($exception->errors()['crop'] ?? [], var_export($crop, true));
        }
    }
}
