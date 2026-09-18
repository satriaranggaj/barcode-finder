<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\SearchFeedback;
use App\Models\User;
use App\Services\SearchEvidence;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class ReferenceQualityGuardTest extends TestCase
{
    use RefreshDatabase;

    private string $directory;

    protected function tearDown(): void
    {
        if (isset($this->directory) && is_dir($this->directory)) {
            collect(new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->directory, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            ))->each(fn ($file) => $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname()));
            rmdir($this->directory);
        }
        parent::tearDown();
    }

    private function confirmWithMetrics(User $user, Product $product, array $metrics): void
    {
        config(['retrieval.feedback_enabled' => true, 'retrieval.query_temp_disk' => 'local', 'retrieval.private_disk' => 'local']);
        Storage::fake('local');
        $image = UploadedFile::fake()->image('q.jpg', 300, 300);
        $token = app(SearchEvidence::class)->stage([
            'results' => [['rank' => 1, 'sku' => $product->sku, 'score' => 0.8]],
            'confidence' => 'high',
            'feedback' => array_merge([
                'master' => base64_encode(file_get_contents($image->getRealPath())),
                'photo_hash' => str_repeat('e', 64), 'dhash' => str_repeat('4', 16),
            ], $metrics),
        ], $user->id);
        $this->actingAs($user)->post(route('search.feedback'), ['token' => $token, 'sku' => $product->sku])
            ->assertRedirect();
    }

    public function test_valid_handheld_like_fixture_is_eligible_without_reasons(): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        $product = Product::create(['sku' => 'RIGHT']);
        $this->confirmWithMetrics($user, $product, ['blur' => 120, 'min_side' => 300, 'crop_pixels' => 90000, 'gray_std' => 45]);
        $row = SearchFeedback::sole();
        $this->assertTrue($row->reference_eligible);
        $this->assertSame([], $row->evidence['quality']['reasons']);
        $this->assertEqualsWithDelta(45.0, $row->evidence['quality']['std'], 0.0001);
    }

    public function test_extreme_blur_is_flagged_but_still_stored_verified(): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        $product = Product::create(['sku' => 'RIGHT']);
        $this->confirmWithMetrics($user, $product, ['blur' => 5, 'min_side' => 300, 'crop_pixels' => 90000, 'gray_std' => 45]);
        $row = SearchFeedback::sole();
        $this->assertFalse($row->reference_eligible);
        $this->assertSame(['low_blur'], $row->evidence['quality']['reasons']);
        // The audit trail stays 'verified'; eligibility gates every learning use.
        $this->assertSame('verified', $row->training_status);
    }

    public function test_tiny_crop_and_blank_capture_are_flagged(): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        $product = Product::create(['sku' => 'RIGHT']);
        $this->confirmWithMetrics($user, $product, ['blur' => 120, 'min_side' => 300, 'crop_pixels' => 900, 'gray_std' => 1.5]);
        $row = SearchFeedback::sole();
        $this->assertFalse($row->reference_eligible);
        $this->assertSame(['object_too_small', 'near_blank'], $row->evidence['quality']['reasons']);
    }

    public function test_malformed_optional_metrics_reject_staging(): void {        config(['retrieval.query_temp_disk' => 'local']);
        Storage::fake('local');
        $user = User::factory()->create();
        $image = UploadedFile::fake()->image('q.jpg', 200, 200);
        $token = app(SearchEvidence::class)->stage([
            'results' => [],
            'feedback' => [
                'master' => base64_encode(file_get_contents($image->getRealPath())),
                'photo_hash' => str_repeat('f', 64), 'dhash' => str_repeat('5', 16),
                'blur' => 100, 'min_side' => 200, 'gray_std' => 'not-a-number',
            ],
        ], $user->id);
        $this->assertNull($token);
        $this->assertSame([], Storage::disk('local')->allFiles('search-pending'));
    }

    public function test_quality_rejected_row_is_excluded_from_both_exporters(): void
    {
        Storage::fake('public');
        $user = User::factory()->create(['role' => 'admin']);
        $product = Product::create(['sku' => 'RIGHT']);
        $this->confirmWithMetrics($user, $product, ['blur' => 5, 'min_side' => 300, 'crop_pixels' => 90000, 'gray_std' => 45]);
        $this->assertFalse(SearchFeedback::sole()->reference_eligible);

        $this->directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'lensku-quality-export-'.Str::uuid();
        $this->artisan('products:export-visual', ['directory' => $this->directory, '--include-verified' => true])
            ->assertSuccessful();
        $this->assertFileDoesNotExist($this->directory.'/RIGHT/verified-1.webp');

        $target = Storage::disk('local')->path('training.jsonl');
        $this->artisan('search:export-training', ['output' => $target])->assertSuccessful();
        $lines = array_values(array_filter(explode("\n", file_get_contents($target))));
        $this->assertSame([], $lines);
        $this->assertNull(SearchFeedback::sole()->training_exported_at);
    }
}
