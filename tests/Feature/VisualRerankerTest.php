<?php

namespace Tests\Feature;

use App\Services\VisualReranker;
use Tests\TestCase;

class VisualRerankerTest extends TestCase
{
    public function test_optional_signals_can_disambiguate_equal_global_scores(): void
    {
        $query = ['version' => 'visual-v1', 'color' => [1, 0], 'texture' => [0, 1], 'proportion' => 2, 'local' => [str_repeat('aa', 32)]];
        $other = ['version' => 'visual-v1', 'color' => [0, 1], 'texture' => [1, 0], 'proportion' => .5, 'local' => [str_repeat('55', 32)]];
        $weights = ['global' => 1, 'color' => 1, 'texture' => 1, 'proportion' => 1, 'local' => 1];
        $ranker = new VisualReranker;
        $this->assertGreaterThan($ranker->score($query, $other, .9, $weights), $ranker->score($query, $query, .9, $weights));
        $this->assertSame(.9, $ranker->score($query, $other, .9, ['global' => 1]));
    }

    public function test_missing_or_wrong_version_does_not_penalize_candidate(): void
    {
        $ranker = new VisualReranker;
        $this->assertSame(.8, $ranker->score(['version' => 'visual-v1'], [], .8, ['global' => 1, 'shape' => 1, 'local' => 1]));
        $this->assertSame(.8, $ranker->score([], [], .8, []));
    }

    public function test_negative_weights_are_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new VisualReranker)->score([], [], .9, ['global' => -1]);
    }
}
