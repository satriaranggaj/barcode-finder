<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\SearchFeedback;
use App\Models\User;
use App\Services\SearchEvidence;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class TrainingSamplesExportTest extends TestCase
{
    use RefreshDatabase;

    private function confirm(User $user, Product $product, string $predictedSku, string $hash, string $dhash): void
    {
        config(['retrieval.feedback_enabled' => true, 'retrieval.query_temp_disk' => 'local', 'retrieval.private_disk' => 'local']);
        $image = UploadedFile::fake()->image('q-'.$hash[0].'.jpg', 300, 300);
        $token = app(SearchEvidence::class)->stage([
            'results' => [['rank' => 1, 'sku' => $predictedSku, 'score' => 0.8]],
            'confidence' => 'high',
            'feedback' => [
                'master' => base64_encode(file_get_contents($image->getRealPath())),
                'photo_hash' => $hash, 'dhash' => $dhash, 'blur' => 100, 'min_side' => 300,
            ],
        ], $user->id);
        $this->actingAs($user)->post(route('search.feedback'), ['token' => $token, 'sku' => $product->sku])
            ->assertRedirect();
    }

    private function exportLines(string $target): array
    {
        $this->artisan('search:export-training', ['output' => $target])->assertSuccessful();
        $lines = array_values(array_filter(explode("\n", file_get_contents($target))));

        return array_map(fn ($line) => json_decode($line, true), $lines);
    }

    public function test_correct_prediction_exports_positive_without_hard_negative(): void
    {
        Storage::fake('local');
        $user = User::factory()->create(['role' => 'admin']);
        $product = Product::create(['sku' => 'RIGHT']);
        $this->confirm($user, $product, 'RIGHT', str_repeat('a', 64), str_repeat('0', 16));

        $rows = $this->exportLines(Storage::disk('local')->path('training.jsonl'));
        $this->assertCount(1, $rows);
        $this->assertSame('RIGHT', $rows[0]['positive_sku']);
        $this->assertNull($rows[0]['hard_negative_sku']);
        // Provenance for audit; anchor is a storage reference, never image bytes.
        $this->assertSame($user->id, $rows[0]['verified_by']);
        $this->assertNotEmpty($rows[0]['verified_at']);
        $this->assertSame(str_repeat('a', 64), $rows[0]['photo_hash']);
        $this->assertSame('local', $rows[0]['anchor']['disk']);
        $this->assertStringStartsWith('verified-search/', $rows[0]['anchor']['path']);
        $this->assertArrayNotHasKey('master', $rows[0]['anchor']);
        // Reproducibility: identity, crop, labels, source and schema travel with the record.
        $this->assertSame('training-jsonl-v1', $rows[0]['dataset_schema']);
        $this->assertSame('confirmed_search', $rows[0]['source']);
        $this->assertNotEmpty($rows[0]['exported_at']);
        $this->assertNotNull(SearchFeedback::sole()->training_exported_at);
    }

    public function test_corrected_prediction_exports_hard_negative_pair(): void
    {
        Storage::fake('local');
        $user = User::factory()->create(['role' => 'admin']);
        $product = Product::create(['sku' => 'RIGHT']);
        $this->confirm($user, $product, 'WRONG', str_repeat('b', 64), str_repeat('1', 16));

        $rows = $this->exportLines(Storage::disk('local')->path('training.jsonl'));
        $this->assertCount(1, $rows);
        $this->assertSame('RIGHT', $rows[0]['positive_sku']);
        $this->assertSame('WRONG', $rows[0]['hard_negative_sku']);
        $this->assertArrayHasKey('selection_source', $rows[0]);
        $this->assertArrayHasKey('crop', $rows[0]['anchor']);
    }

    public function test_unverified_and_pending_rows_are_never_exported(): void
    {
        Storage::fake('local');
        $user = User::factory()->create(['role' => 'admin']);
        $product = Product::create(['sku' => 'RIGHT']);
        $this->confirm($user, $product, 'RIGHT', str_repeat('c', 64), str_repeat('2', 16));
        // A row that was never staff-confirmed must not leak into training data.
        SearchFeedback::create([
            'user_id' => $user->id, 'confirmed_product_id' => $product->id,
            'predicted_sku' => 'RIGHT', 'confirmed_sku' => 'RIGHT',
            'disk' => 'local', 'query_image_path' => 'verified-search/pending.webp',
            'photo_hash' => str_repeat('d', 64), 'dhash' => str_repeat('3', 16),
            'training_status' => 'pending',
        ]);

        $rows = $this->exportLines(Storage::disk('local')->path('training.jsonl'));
        $this->assertCount(1, $rows);
        $this->assertSame(str_repeat('c', 64), $rows[0]['photo_hash']);
        $this->assertNotNull(SearchFeedback::where('photo_hash', str_repeat('c', 64))->sole()->training_exported_at);
        $this->assertNull(SearchFeedback::where('photo_hash', str_repeat('d', 64))->sole()->training_exported_at);
    }

    public function test_empty_export_succeeds_without_marking(): void
    {
        Storage::fake('local');
        $rows = $this->exportLines(Storage::disk('local')->path('training.jsonl'));
        $this->assertSame([], $rows);
    }

    public function test_missing_image_or_rejected_quality_is_not_exported(): void
    {
        Storage::fake('local');
        $user = User::factory()->create(['role' => 'admin']);
        $product = Product::create(['sku' => 'RIGHT']);
        $this->confirm($user, $product, 'RIGHT', str_repeat('a', 64), str_repeat('0', 16));
        $row = SearchFeedback::sole();
        $row->update(['reference_eligible' => false]);
        $this->assertSame([], $this->exportLines(Storage::disk('local')->path('quality.jsonl')));
        $row->update(['reference_eligible' => true]);
        Storage::disk($row->disk)->delete($row->query_image_path);
        $this->assertSame([], $this->exportLines(Storage::disk('local')->path('missing.jsonl')));
        $this->assertNull($row->fresh()->training_exported_at);
    }

    public function test_renamed_product_does_not_export_a_stale_sku_label(): void
    {
        Storage::fake('local');
        $user = User::factory()->create(['role' => 'admin']);
        $product = Product::create(['sku' => 'RIGHT']);
        $this->confirm($user, $product, 'RIGHT', str_repeat('e', 64), str_repeat('9', 16));
        $product->update(['sku' => 'RENAMED']);
        $this->assertSame([], $this->exportLines(Storage::disk('local')->path('renamed.jsonl')));
        $this->assertNull(SearchFeedback::sole()->training_exported_at);
    }
}
