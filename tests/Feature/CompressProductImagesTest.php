<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductPhoto;
use App\Services\ProductImageOptimizer;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\Concerns\CreatesProductImages;
use Tests\TestCase;

class CompressProductImagesTest extends TestCase
{
    use CreatesProductImages, RefreshDatabase;

    private function photo(string $path = 'products/original.jpg'): ProductPhoto
    {
        $product = Product::create(['sku' => $path, 'photo' => $path, 'embedding' => '[0.4,0.8]']);
        Storage::disk('public')->put($path, $this->productImageBytes());

        return $product->photos()->create(['path' => $path, 'embedding' => '[0.4,0.8]']);
    }

    public function test_compression_updates_both_paths_without_reembedding_and_is_idempotent(): void
    {
        Storage::fake('public');
        Http::preventStrayRequests();
        $photo = $this->photo();
        $oldPath = $photo->path;

        $this->artisan('products:compress-images', ['--sleep' => 0, '--chunk' => 1])->assertSuccessful();

        $photo->refresh();
        $this->assertSame('webp', $photo->storage_optimization);
        $this->assertNotNull($photo->storage_optimized_at);
        $this->assertSame('[0.4,0.8]', $photo->embedding);
        $this->assertSame('[0.4,0.8]', $photo->product->embedding);
        $this->assertSame($photo->path, $photo->product->photo);
        Storage::disk('public')->assertMissing($oldPath);
        Storage::disk('public')->assertExists($photo->path);
        $hash = hash_file('sha256', Storage::disk('public')->path($photo->path));

        $this->artisan('products:compress-images', ['--sleep' => 0])->assertSuccessful();

        $this->assertSame($hash, hash_file('sha256', Storage::disk('public')->path($photo->fresh()->path)));
        $this->assertCount(1, Storage::disk('public')->allFiles());
        Http::assertNothingSent();
    }

    public function test_dry_run_leaves_files_and_database_unchanged(): void
    {
        Storage::fake('public');
        $photo = $this->photo();
        $hash = hash_file('sha256', Storage::disk('public')->path($photo->path));

        $this->artisan('products:compress-images', ['--dry-run' => true, '--sleep' => 0])
            ->expectsOutputToContain('Processed: 1')->assertSuccessful();

        $this->assertNull($photo->fresh()->storage_optimized_at);
        $this->assertSame($photo->path, $photo->fresh()->path);
        $this->assertSame($hash, hash_file('sha256', Storage::disk('public')->path($photo->path)));
        $this->assertCount(1, Storage::disk('public')->allFiles());
    }

    public function test_limit_and_resume_cross_batch_boundaries(): void
    {
        Storage::fake('public');
        $first = $this->photo('products/first.jpg');
        $second = $this->photo('products/second.jpg');
        $third = $this->photo('products/third.jpg');

        $this->artisan('products:compress-images', ['--chunk' => 1, '--limit' => 2, '--sleep' => 0])->assertSuccessful();

        $this->assertNotNull($first->fresh()->storage_optimized_at);
        $this->assertNotNull($second->fresh()->storage_optimized_at);
        $this->assertNull($third->fresh()->storage_optimized_at);

        $this->artisan('products:compress-images', ['--after-id' => $second->id, '--sleep' => 0])->assertSuccessful();

        $this->assertNotNull($third->fresh()->storage_optimized_at);
    }

    public function test_bad_files_do_not_stop_other_rows(): void
    {
        Storage::fake('public');
        $broken = $this->photo('products/broken.jpg');
        Storage::disk('public')->put($broken->path, 'not an image');
        $missing = $this->photo('products/missing.jpg');
        Storage::disk('public')->delete($missing->path);
        $good = $this->photo('products/good.jpg');

        $this->artisan('products:compress-images', ['--sleep' => 0, '--chunk' => 1])
            ->expectsOutputToContain('Failed: 2')->assertFailed();

        $this->assertNull($broken->fresh()->storage_optimized_at);
        $this->assertNull($missing->fresh()->storage_optimized_at);
        $this->assertSame('not an image', Storage::disk('public')->get($broken->path));
        $this->assertNotNull($good->fresh()->storage_optimized_at);
    }

    public function test_shared_file_is_not_deleted_while_referenced(): void
    {
        Storage::fake('public');
        $first = $this->photo();
        $second = $first->product->photos()->create(['path' => $first->path]);

        $this->artisan('products:compress-images', ['--limit' => 1, '--sleep' => 0])->assertSuccessful();

        Storage::disk('public')->assertExists($second->path);
        $this->assertSame($second->path, $second->fresh()->path);
        $this->assertNotSame($first->path, $first->fresh()->path);
    }

    public function test_keep_originals_retains_original_for_review(): void
    {
        Storage::fake('public');
        $photo = $this->photo();

        $this->artisan('products:compress-images', ['--keep-originals' => true, '--sleep' => 0])->assertSuccessful();

        Storage::disk('public')->assertExists($photo->path);
        Storage::disk('public')->assertExists($photo->fresh()->path);
        $this->assertCount(2, Storage::disk('public')->allFiles());
    }

    public function test_database_failure_preserves_original(): void
    {
        Storage::fake('public');
        $photo = $this->photo();
        DB::unprepared("CREATE TRIGGER fail_compression BEFORE UPDATE ON product_photos BEGIN SELECT RAISE(ABORT, 'test database failure'); END");
        try {
            $this->artisan('products:compress-images', ['--sleep' => 0])->assertFailed();
        } finally {
            DB::unprepared('DROP TRIGGER fail_compression');
        }

        $this->assertSame($photo->path, $photo->fresh()->path);
        $this->assertNull($photo->fresh()->storage_optimized_at);
        Storage::disk('public')->assertExists($photo->path);
        $this->assertCount(1, Storage::disk('public')->allFiles());
    }

    public function test_concurrent_command_is_rejected(): void
    {
        $lock = fopen(storage_path('framework/products-compress-images.lock'), 'c');
        flock($lock, LOCK_EX | LOCK_NB);
        try {
            $this->artisan('products:compress-images', ['--sleep' => 0])->assertFailed();
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }

    }

    public function test_pending_batch_is_bounded_with_one_hundred_thousand_reference_rows(): void
    {
        Storage::fake('public');
        $product = Product::create(['sku' => 'SCALE']);
        $batch = array_fill(0, 500, [
            'product_id' => $product->id, 'path' => 'products/completed.webp',
            'storage_optimized_at' => '2026-01-01 00:00:00', 'embedding' => '[0.4,0.8]',
        ]);
        for ($i = 0; $i < 200; $i++) {
            DB::table('product_photos')->insert($batch);
        }
        unset($batch);
        Storage::disk('public')->put('products/pending.jpg', $this->productImageBytes());
        $product->photos()->createMany([
            ['path' => 'products/pending.jpg'], ['path' => 'products/pending.jpg'],
        ]);
        $selects = [];
        DB::listen(function ($event) use (&$selects): void {
            if (str_starts_with($event->sql, 'select "id", "product_id", "path"')) {
                $selects[] = $event->sql;
            }
        });
        $baseline = memory_get_usage(true);

        $this->artisan('products:compress-images', ['--dry-run' => true, '--limit' => 2, '--chunk' => 1, '--sleep' => 0])
            ->expectsOutputToContain('Last ID: 100002')->assertSuccessful();

        $this->assertCount(2, $selects);
        foreach ($selects as $sql) {
            $this->assertStringContainsString('limit 1', $sql);
            $this->assertStringNotContainsString('embedding', $sql);
            $this->assertStringNotContainsString('offset', $sql);
        }
        $this->assertLessThan(16 * 1048576, memory_get_usage(true) - $baseline);
        $plan = DB::select('EXPLAIN QUERY PLAN SELECT id, product_id, path FROM product_photos WHERE storage_optimized_at IS NULL AND id > 100000 ORDER BY id LIMIT 1');
        $this->assertStringContainsString('product_photos_storage_pending_index', $plan[0]->detail);
    }

    public function test_cleanup_never_removes_a_committed_reference(): void
    {
        Storage::fake('public');
        $photo = $this->photo();

        $this->assertFalse(app(ProductImageOptimizer::class)->discardUnreferenced($photo->path));

        Storage::disk('public')->assertExists($photo->path);
    }

    public function test_failed_disk_write_is_reported_without_changing_the_source(): void
    {
        $image = $this->productImage();
        $optimizer = app(ProductImageOptimizer::class);
        $prepared = $optimizer->prepare($image->getRealPath(), 88);
        $disk = Mockery::mock(FilesystemAdapter::class);
        $disk->shouldReceive('put')->once()->andReturn(false);
        $disk->shouldReceive('delete')->once()->andReturn(true);
        Storage::shouldReceive('disk')->with('public')->andReturn($disk);

        try {
            $optimizer->store($prepared);
            $this->fail('Expected storage failure.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Penyimpanan foto gagal.', $exception->getMessage());
            $this->assertFileExists($image->getRealPath());
        } finally {
            $prepared->cleanup();
        }
    }
}
