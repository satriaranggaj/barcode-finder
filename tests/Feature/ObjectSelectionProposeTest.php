<?php

namespace Tests\Feature;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ObjectSelectionProposeTest extends TestCase
{
    public function test_candidates_with_metadata_are_passed_through(): void
    {
        Http::preventStrayRequests();
        Http::fake([rtrim(config('services.ai.url'), '/').'/select' => [
            'boxes' => [['x' => .1, 'y' => .1, 'width' => .5, 'height' => .5]],
            'candidates' => [
                ['box' => ['x' => .1, 'y' => .1, 'width' => .5, 'height' => .5], 'source' => 'grabcut_foreground', 'score' => .25],
                ['box' => ['x' => .2, 'y' => .2, 'width' => .4, 'height' => .4], 'source' => 'foreground_fallback', 'score' => .16],
            ],
            'reason' => 'foreground_proposal',
        ]]);

        $this->postJson(route('object-selection.propose'), ['image' => UploadedFile::fake()->image('proposal.jpg', 200, 150)])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('reason', 'foreground_proposal')
            ->assertJsonCount(2, 'candidates')
            ->assertJsonCount(2, 'boxes')
            ->assertJsonPath('candidates.0.source', 'grabcut_foreground')
            ->assertJsonPath('candidates.0.score', 0.25)
            ->assertJsonPath('candidates.1.source', 'foreground_fallback')
            ->assertJsonPath('candidates.1.score', 0.16);
    }

    public function test_legacy_boxes_only_response_keeps_working(): void
    {
        Http::preventStrayRequests();
        Http::fake([rtrim(config('services.ai.url'), '/').'/select' => [
            'boxes' => [['x' => .1, 'y' => .2, 'width' => .6, 'height' => .4]],
            'reason' => 'foreground_proposal',
        ]]);

        $this->postJson(route('object-selection.propose'), ['image' => UploadedFile::fake()->image('legacy.jpg', 200, 150)])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonCount(1, 'candidates')
            ->assertJsonCount(1, 'boxes')
            ->assertJsonPath('candidates.0.source', 'unknown')
            ->assertJsonPath('candidates.0.score', null)
            ->assertJsonPath('candidates.0.box.x', .1);
    }

    public function test_invalid_candidate_is_skipped_without_failing_the_others(): void
    {
        Http::preventStrayRequests();
        Http::fake([rtrim(config('services.ai.url'), '/').'/select' => [
            'candidates' => [
                ['box' => ['x' => 2, 'y' => 0, 'width' => .5, 'height' => .5], 'source' => 'broken', 'score' => .9],
                ['box' => ['x' => .1, 'y' => .1, 'width' => .5, 'height' => .5], 'source' => 'grabcut_foreground', 'score' => .25],
            ],
            'reason' => 'foreground_proposal',
        ]]);

        $this->postJson(route('object-selection.propose'), ['image' => UploadedFile::fake()->image('mixed.jpg', 200, 150)])
            ->assertOk()
            ->assertJsonCount(1, 'candidates')
            ->assertJsonCount(1, 'boxes')
            ->assertJsonPath('candidates.0.source', 'grabcut_foreground');
    }

    public function test_backend_failure_returns_selection_unavailable(): void
    {
        Http::preventStrayRequests();
        Http::fake([rtrim(config('services.ai.url'), '/').'/select' => Http::response('down', 500)]);

        $this->postJson(route('object-selection.propose'), ['image' => UploadedFile::fake()->image('fail.jpg', 200, 150)])
            ->assertOk()
            ->assertJsonPath('success', false)
            ->assertJsonPath('reason', 'selection_unavailable')
            ->assertJsonCount(0, 'boxes')
            ->assertJsonCount(0, 'candidates');
    }
}
