<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductPhoto;
use App\Models\User;
use App\Services\ImageFeatureClient;
use App\Services\ImageSearch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesProductImages;
use Tests\TestCase;

class ImagePipelineTest extends TestCase
{
    use CreatesProductImages, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        Http::preventStrayRequests();
    }

    private function features(): array
    {
        return ['version' => ImageFeatureClient::VERSION, 'embedding_model' => ImageFeatureClient::MODEL,
            'model_revision' => ImageFeatureClient::MODEL_REVISION,
            'embedding' => array_merge([1], array_fill(0, 511, 0)),
            'preprocessing' => ['mode' => 'segmented']];
    }

    private function photo(string $sku = 'TEST'): ProductPhoto
    {
        $product = Product::firstOrCreate(['sku' => $sku]);
        $file = $this->productImage();
        $path = $file->store('products', 'public');

        return ProductPhoto::create(['product_id' => $product->id, 'path' => $path, 'embedding' => '[1,0,0]']);
    }

    public function test_reindex_is_explicit_idempotentent_resumable_and_preserves_original(): void
    {
        $first = $this->photo();
        $this->photo();
        $bytes = Storage::disk('public')->get($first->path);
        Http::fake(['*/features' => Http::response($this->features())]);
        $this->artisan('products:reindex-images --dry-run --limit=1 --sleep=0')->assertSuccessful();
        $this->assertDatabaseCount('product_photo_features', 0);
        $this->artisan('products:reindex-images --limit=1 --chunk=1 --sleep=0')->assertSuccessful();
        $this->assertDatabaseCount('product_photo_features', 1);
        $this->artisan('products:reindex-images --after-id='.$first->id.' --sleep=0')->assertSuccessful();
        $this->artisan('products:reindex-images --sleep=0')->assertSuccessful();
        $this->assertDatabaseCount('product_photo_features', 2);
        Http::assertSentCount(3);
        $this->assertSame('[1,0,0]', $first->fresh()->embedding);
        $this->assertSame($bytes, Storage::disk('public')->get($first->path));
    }

    public function test_failed_rows_are_retryable_and_other_rows_continue(): void
    {
        $this->photo();
        $this->photo();
        Http::fake(['*/features' => Http::sequence()->pushStatus(503)->push($this->features())->push($this->features())]);
        $this->artisan('products:reindex-images --sleep=0')->assertFailed();
        $this->assertDatabaseCount('product_photo_features', 1);
        $this->artisan('products:reindex-images --sleep=0')->assertSuccessful();
        $this->assertDatabaseCount('product_photo_features', 2);
    }

    public function test_concurrent_photo_change_does_not_store_stale_features(): void
    {
        $photo = $this->photo();
        Http::fake(function () use ($photo) {
            $photo->update(['path' => 'products/replaced.jpg']);

            return Http::response($this->features());
        });
        $this->artisan('products:reindex-images --sleep=0')->assertFailed();
        $this->assertDatabaseCount('product_photo_features', 0);
    }

    public function test_new_features_failure_does_not_break_multiple_upload(): void
    {
        config(['image_search.index_uploads' => true]);
        Http::fake(['*/embed' => Http::response(['embedding' => [1, 0, 0]]), '*/features' => Http::response([], 503)]);
        $admin = User::factory()->create(['role' => 'admin']);
        $product = Product::create(['sku' => 'NEW']);
        $this->actingAs($admin)->post('/admin/products/'.$product->id.'/photo', [
            'images' => [$this->productImage(), $this->productImage()],
        ])->assertSessionHasNoErrors();
        $this->assertDatabaseCount('product_photos', 2);
        $this->assertDatabaseCount('product_photo_features', 0);
    }

    public function test_mismatched_vector_contract_is_rejected(): void
    {
        $this->photo();
        Http::fake(['*/features' => Http::response(array_replace($this->features(), ['version' => 'wrong']))]);
        $this->artisan('products:reindex-images --sleep=0')->assertFailed();
        $this->assertDatabaseCount('product_photo_features', 0);
    }

    public function test_search_groups_multiple_photos_using_best_match(): void
    {
        $first = $this->photo('ONE');
        $best = $this->photo('ONE');
        $this->photo('TWO');
        $first->update(['embedding' => '[0.8,0.6,0]']);
        Http::fake(['*/embed' => Http::response(['embedding' => [1, 0, 0]])]);
        $response = $this->post('/search', ['image' => $this->productImage()])->assertOk();
        $results = $response->viewData('results');
        $this->assertCount(2, $results);
        $this->assertSame($best->path, $results->firstWhere('sku', 'ONE')->photo);
    }

    public function test_incomplete_reindex_uses_legacy_for_whole_query(): void
    {
        config(['image_search.pipeline' => ImageFeatureClient::VERSION]);
        $this->photo();
        Http::fake(['*/embed' => Http::response(['embedding' => [1, 0, 0]])]);
        $this->post('/search', ['image' => $this->productImage()])->assertOk()->assertViewHas('error', null);
        Http::assertSentCount(1);
        Http::assertNotSent(fn ($request) => str_ends_with($request->url(), '/features'));
    }

    public function test_completed_reindex_uses_only_compatible_features(): void
    {
        config(['image_search.pipeline' => ImageFeatureClient::VERSION]);
        $photo = $this->photo();
        app(ImageFeatureClient::class)->store($photo->id, $photo->path, str_repeat('a', 64), $this->features());
        Http::fake(['*/features' => Http::response($this->features())]);
        $response = $this->post('/search', ['image' => $this->productImage()])->assertOk()->assertViewHas('error', null);
        $this->assertCount(1, $response->viewData('results'));
        Http::assertSentCount(1);
    }

    public function test_filtered_candidates_stay_empty_without_legacy_refill(): void
    {
        // A controlled test threshold, never an application calibration value.
        config(['image_search.pipeline' => ImageFeatureClient::VERSION, 'image_search.final_min_score' => .9]);
        $photo = $this->photo();
        $reference = $this->features();
        $reference['embedding'] = array_merge([.8, .6], array_fill(0, 510, 0));
        app(ImageFeatureClient::class)->store($photo->id, $photo->path, str_repeat('a', 64), $reference);
        $this->assertCount(1, app(ImageSearch::class)->candidates($this->features()['embedding'], true));
        Http::fake(['*/features' => Http::response($this->features())]);

        $response = $this->post('/search', ['image' => $this->productImage()])
            ->assertOk()->assertViewHas('error', null)
            ->assertSeeText('Tidak ditemukan barang yang cukup mirip');

        $this->assertCount(0, $response->viewData('results'));
        Http::assertSentCount(1);
        Http::assertNotSent(fn ($request) => str_ends_with($request->url(), '/embed'));
    }

    public function test_feature_service_failure_falls_back_to_legacy_search(): void
    {
        config(['image_search.pipeline' => ImageFeatureClient::VERSION]);
        $photo = $this->photo();
        app(ImageFeatureClient::class)->store($photo->id, $photo->path, str_repeat('a', 64), $this->features());
        Http::fake(['*/features' => Http::response([], 503), '*/embed' => Http::response(['embedding' => [1, 0, 0]])]);
        $this->post('/search', ['image' => $this->productImage()])->assertOk()->assertViewHas('error', null);
        Http::assertSentCount(2);
    }

    public function test_successful_multiple_upload_stores_versioned_features_and_legacy_vectors(): void
    {
        config(['image_search.index_uploads' => true]);
        Http::fake(['*/embed' => Http::response(['embedding' => [1, 0, 0]]), '*/features' => Http::response($this->features())]);
        $admin = User::factory()->create(['role' => 'admin']);
        $product = Product::create(['sku' => 'BOTH']);
        $this->actingAs($admin)->post(route('admin.products.photo.store', $product), [
            'images' => [$this->productImage(), $this->productImage()],
        ])->assertSessionHasNoErrors();
        $this->assertDatabaseCount('product_photo_features', 2);
        foreach (ProductPhoto::all() as $photo) {
            $this->assertSame('[1,0,0]', $photo->embedding);
            $this->assertDatabaseHas('product_photo_features', [
                'product_photo_id' => $photo->id, 'source_path' => $photo->path,
                'source_sha256' => hash('sha256', Storage::disk('public')->get($photo->path)),
            ]);
        }
    }

    public function test_reindex_keeps_memory_bounded_with_one_hundred_thousand_completed_rows(): void
    {
        $product = Product::create(['sku' => 'SCALE']);
        $connection = DB::connection();
        $connection->disableQueryLog();
        for ($base = 1; $base <= 100000; $base += 1000) {
            $photos = $features = [];
            for ($id = $base; $id < $base + 1000; $id++) {
                $photos[] = ['id' => $id, 'product_id' => $product->id, 'path' => 'products/shared.jpg'];
                $features[] = ['product_photo_id' => $id, 'version' => ImageFeatureClient::VERSION, 'source_path' => 'products/shared.jpg',
                    'source_sha256' => str_repeat('a', 64), 'embedding' => '[]', 'metadata' => '{}', 'processed_at' => now()];
            }
            $connection->table('product_photos')->insert($photos);
            $connection->table('product_photo_features')->insert($features);
        }
        unset($photos, $features);
        $pending = $this->photo('SCALE');
        Http::fake(['*/features' => Http::response($this->features())]);
        $before = memory_get_usage(true);
        $this->artisan('products:reindex-images --chunk=1 --limit=1 --sleep=0')->assertSuccessful();
        $this->assertLessThan(16 * 1024 * 1024, memory_get_usage(true) - $before);
        $this->assertDatabaseHas('product_photo_features', ['product_photo_id' => $pending->id]);
        Http::assertSentCount(1);
    }

    public function test_reindex_lock_prevents_concurrent_work(): void
    {
        $lock = fopen(storage_path('framework/products-reindex-images.lock'), 'c');
        flock($lock, LOCK_EX);
        try {
            $this->artisan('products:reindex-images --sleep=0')->assertFailed();
            Http::assertNothingSent();
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    public function test_benchmark_counts_ranks_and_never_writes_database(): void
    {
        $feature = ['embedding' => [1, 0, 0]];
        $other = ['embedding' => [0, 1, 0]];
        $fixture = ['dataset_note' => 'Synthetic metric test', 'environment' => [], 'latency_ms' => [],
            'references' => [['sku' => 'A', 'old' => $feature, 'new' => $feature], ['sku' => 'B', 'old' => $other, 'new' => $other]],
            'queries' => [['sku' => 'A', 'case' => 'fixture', 'old' => $feature, 'new' => $feature]]];
        $input = tempnam(sys_get_temp_dir(), 'lensku-bench-');
        $output = tempnam(sys_get_temp_dir(), 'lensku-score-');
        try {
            file_put_contents($input, json_encode($fixture));
            $this->artisan('images:benchmark', ['features' => $input, '--output' => $output])->assertSuccessful();
            $report = json_decode(file_get_contents($output), true);
            $this->assertCount(8, $report['runs']);
            $this->assertEquals(1, $report['runs'][0]['top1']);
            $this->assertEquals(1, $report['runs'][0]['recall_at_k']);
            $this->assertDatabaseCount('product_photos', 0);
        } finally {
            unlink($input);
            unlink($output);
        }
    }
}
