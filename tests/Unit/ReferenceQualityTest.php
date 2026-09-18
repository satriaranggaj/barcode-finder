<?php

namespace Tests\Unit;

use App\Services\ReferenceQuality;
use Tests\TestCase;

class ReferenceQualityTest extends TestCase
{
    public function test_clear_handheld_like_capture_is_accepted_with_metrics(): void
    {
        $result = ReferenceQuality::evaluate(['blur' => 120, 'min_side' => 300, 'crop_pixels' => 90000, 'gray_std' => 45]);
        $this->assertTrue($result['eligible']);
        $this->assertSame([], $result['reasons']);
        $this->assertSame(['blur' => 120.0, 'min_side' => 300, 'crop_pixels' => 90000, 'std' => 45.0], $result['metrics']);
    }

    public function test_each_guard_reports_its_own_reason(): void
    {
        $cases = [
            [['blur' => 5, 'min_side' => 300, 'crop_pixels' => 90000, 'gray_std' => 45], ['low_blur']],
            [['blur' => 120, 'min_side' => 64, 'crop_pixels' => 90000, 'gray_std' => 45], ['too_small']],
            [['blur' => 120, 'min_side' => 300, 'crop_pixels' => 2500, 'gray_std' => 45], ['object_too_small']],
            [['blur' => 120, 'min_side' => 300, 'crop_pixels' => 90000, 'gray_std' => 2.0], ['near_blank']],
            [['blur' => 5, 'min_side' => 64, 'crop_pixels' => 100, 'gray_std' => 1.0],
                ['low_blur', 'too_small', 'object_too_small', 'near_blank']],
        ];
        foreach ($cases as [$quality, $reasons]) {
            $result = ReferenceQuality::evaluate($quality);
            $this->assertFalse($result['eligible']);
            $this->assertSame($reasons, $result['reasons']);
        }
    }

    public function test_missing_required_metrics_fail_closed_with_reasons(): void
    {
        $result = ReferenceQuality::evaluate([]);
        $this->assertFalse($result['eligible']);
        $this->assertSame(['missing_blur', 'missing_size'], $result['reasons']);
    }

    public function test_missing_newer_metrics_stay_lenient(): void
    {
        $result = ReferenceQuality::evaluate(['blur' => 100, 'min_side' => 300]);
        $this->assertTrue($result['eligible']);
        $this->assertNull($result['metrics']['crop_pixels']);
        $this->assertNull($result['metrics']['std']);
    }

    public function test_thresholds_are_configurable(): void
    {
        config(['retrieval.reference_min_blur' => 200, 'retrieval.reference_min_side' => 400,
            'retrieval.reference_min_crop_pixels' => 200000, 'retrieval.reference_min_std' => 60]);
        $result = ReferenceQuality::evaluate(['blur' => 120, 'min_side' => 300, 'crop_pixels' => 90000, 'gray_std' => 45]);
        $this->assertFalse($result['eligible']);
        $this->assertSame(['low_blur', 'too_small', 'object_too_small', 'near_blank'], $result['reasons']);
    }

    public function test_output_is_transparent_without_probability_claims(): void
    {
        $result = ReferenceQuality::evaluate(['blur' => 5, 'min_side' => 300, 'crop_pixels' => 90000, 'gray_std' => 45]);
        $this->assertArrayHasKey('eligible', $result);
        $this->assertArrayHasKey('reasons', $result);
        $this->assertArrayHasKey('metrics', $result);
        $this->assertStringNotContainsStringIgnoringCase('probab', json_encode($result));
        $this->assertContains('low_blur', $result['reasons']);
    }
}
