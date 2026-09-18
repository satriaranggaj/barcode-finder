<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\SearchFeedback;
use App\Models\User;
use App\Services\SearchEvidence;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SearchFeedbackPersistenceTest extends TestCase
{
    use RefreshDatabase;

    private function stageReport(User $user, array $overrides = []): string
    {
        config(['retrieval.query_temp_disk' => 'local', 'retrieval.private_disk' => 'local']);
        Storage::fake('local');
        $image = UploadedFile::fake()->image('q.jpg', 300, 300);
        $report = array_merge([
            'results' => [
                ['rank' => 1, 'sku' => 'WRONG', 'image_id' => 'WRONG/front.webp', 'score' => 0.7,
                    'visual_score' => 0.72, 'siglip_score' => 0.7, 'dino_score' => 0.74, 'local_score' => 0.3,
                    'matched_images' => 1],
                ['rank' => 2, 'sku' => 'RIGHT', 'score' => 0.2],
            ],
            'confidence' => 'medium',
            'score_gap' => 0.5,
            'query' => ['reason' => 'manual_selection', 'models' => ['siglip', 'dino']],
            'feedback' => [
                'master' => base64_encode(file_get_contents($image->getRealPath())),
                'photo_hash' => str_repeat('a', 64), 'dhash' => str_repeat('0', 16),
                'blur' => 100, 'min_side' => 300, 'crop_pixels' => 90000,
            ],
        ], $overrides);

        return app(SearchEvidence::class)->stage($report, $user->id, 'session-test', 'manual');
    }

    public function test_confirmation_persists_normalized_evidence_columns(): void
    {
        config(['retrieval.feedback_enabled' => true]);
        $user = User::factory()->create(['role' => 'admin']);
        $product = Product::create(['sku' => 'RIGHT']);
        $token = $this->stageReport($user);
        $this->actingAs($user)->post(route('search.feedback'), ['token' => $token, 'sku' => 'RIGHT'])
            ->assertRedirect(route('products.show', $product));

        $row = SearchFeedback::sole();
        $this->assertSame('WRONG', $row->predicted_sku);
        $this->assertSame('RIGHT', $row->confirmed_sku);
        $this->assertSame('verified', $row->training_status);
        $this->assertSame('medium', $row->confidence);
        $this->assertEqualsWithDelta(0.5, $row->score_gap, 0.0001);
        $this->assertSame('manual', $row->selection_source);
        $this->assertCount(2, $row->candidates);
        $this->assertSame('WRONG', $row->candidates[0]['sku']);
        $this->assertEqualsWithDelta(0.7, $row->candidates[0]['score'], 0.0001);
        $this->assertEqualsWithDelta(0.74, $row->candidates[0]['dino_score'], 0.0001);
        // Sanitized snapshot keeps exporter keys, drops bytes and unbounded blobs.
        $this->assertSame('session-test', $row->evidence['capture_group']);
        $this->assertSame('manual_selection', $row->evidence['query']['reason']);
        $this->assertArrayNotHasKey('master', $row->evidence['quality']);
        $encoded = json_encode(['candidates' => $row->candidates, 'evidence' => $row->evidence]);
        $this->assertLessThanOrEqual(32768, strlen($encoded));
        // Only the confirmed image persists; the transient staged file is gone.
        Storage::disk('local')->assertExists($row->query_image_path);
        $this->assertSame([], Storage::disk('local')->allFiles('search-pending'));
    }

    public function test_embedding_candidate_is_rejected_without_persisting(): void
    {
        config(['retrieval.feedback_enabled' => true]);
        $user = User::factory()->create(['role' => 'admin']);
        Product::create(['sku' => 'RIGHT']);
        $token = $this->stageReport($user, [
            'results' => [['sku' => 'WRONG', 'score' => 0.7, 'embedding' => [0.1, 0.2]]],
        ]);
        $this->actingAs($user)->post(route('search.feedback'), ['token' => $token, 'sku' => 'RIGHT'])
            ->assertSessionHasErrors('token');
        $this->assertSame(0, SearchFeedback::count());
    }

    public function test_invalid_confidence_is_rejected_without_persisting(): void
    {
        config(['retrieval.feedback_enabled' => true]);
        $user = User::factory()->create(['role' => 'admin']);
        Product::create(['sku' => 'RIGHT']);
        $token = $this->stageReport($user, ['confidence' => 'certain']);
        $this->actingAs($user)->post(route('search.feedback'), ['token' => $token, 'sku' => 'RIGHT'])
            ->assertSessionHasErrors('token');
        $this->assertSame(0, SearchFeedback::count());
    }

    public function test_legacy_staged_rows_without_source_still_confirm(): void
    {
        config(['retrieval.feedback_enabled' => true, 'retrieval.query_temp_disk' => 'local', 'retrieval.private_disk' => 'local']);
        Storage::fake('local');
        $user = User::factory()->create(['role' => 'admin']);
        $product = Product::create(['sku' => 'RIGHT']);
        $image = UploadedFile::fake()->image('q.jpg', 300, 300);
        // Staged before selection_source was threaded through: no source key.
        $token = app(SearchEvidence::class)->stage([
            'results' => [['sku' => 'WRONG', 'score' => 0.7]],
            'feedback' => [
                'master' => base64_encode(file_get_contents($image->getRealPath())),
                'photo_hash' => str_repeat('c', 64), 'dhash' => str_repeat('1', 16),
                'blur' => 100, 'min_side' => 300,
            ],
        ], $user->id);
        $this->actingAs($user)->post(route('search.feedback'), ['token' => $token, 'sku' => 'RIGHT'])
            ->assertRedirect(route('products.show', $product));
        $row = SearchFeedback::sole();
        $this->assertNull($row->selection_source);
        $this->assertSame('WRONG', $row->predicted_sku);
    }

    public function test_evidence_migration_is_reversible(): void
    {
        $columns = ['candidates', 'confidence', 'score_gap', 'selection_source'];
        foreach ($columns as $column) {
            $this->assertTrue(Schema::hasColumn('search_feedback', $column));
        }
        // DDL runs inside the RefreshDatabase transaction, so exercising
        // down()/up() here cannot leak into other tests.
        $migration = require base_path('database/migrations/2026_09_17_000000_add_feedback_evidence_columns.php');
        $migration->down();
        foreach ($columns as $column) {
            $this->assertFalse(Schema::hasColumn('search_feedback', $column));
        }
        // Pre-existing columns survive the rollback.
        $this->assertTrue(Schema::hasColumn('search_feedback', 'evidence'));
        $migration->up();
        foreach ($columns as $column) {
            $this->assertTrue(Schema::hasColumn('search_feedback', $column));
        }
    }
}
