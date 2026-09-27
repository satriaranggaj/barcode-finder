<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Services\VisualIndexBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * JSON state-guard behavior for atomic CAS (driver: whatever runs the suite,
 * normally SQLite in-memory).
 *
 * crop is `$table->json()->nullable()` on both product_photos and
 * search_feedback: NULL must match only NULL, JSON must match only the
 * identical value, and NULL↔JSON transitions must never complete.
 */
class JsonStateGuardTest extends TestCase
{
    use RefreshDatabase;

    private function storedPhoto(Product $product, string $name, ?array $crop = null): \App\Models\ProductPhoto
    {
        Storage::fake('public');
        Storage::disk('public')->put($name, 'fake-image-bytes');

        return $product->photos()->create([
            'path' => $name, 'disk' => 'public', 'index_status' => 'pending', 'crop' => $crop,
        ]);
    }

    private function partialBuilder(): VisualIndexBuilder
    {
        return \Mockery::mock(VisualIndexBuilder::class)->makePartial()
            ->shouldAllowMockingProtectedMethods();
    }

    private function succeed(VisualIndexBuilder $builder, ?callable $duringBuild = null): void
    {
        $builder->shouldReceive('runBuilder')->once()->andReturnUsing(function () use ($duringBuild) {
            if ($duringBuild !== null) {
                $duringBuild();
            }

            return ['added' => 1, 'skipped' => 0, 'generation' => 'generation-json'];
        });
    }

    private function cropV1(): array
    {
        return ['x' => 0.1, 'y' => 0.2, 'width' => 0.5, 'height' => 0.5];
    }

    private function cropV2(): array
    {
        return ['x' => 0.4, 'y' => 0.4, 'width' => 0.2, 'height' => 0.2];
    }

    public function test_unchanged_json_crop_completes(): void
    {
        $product = Product::create(['sku' => 'JSON-A']);
        $photo = $this->storedPhoto($product, 'products/ja.jpg', $this->cropV1());

        $builder = $this->partialBuilder();
        $this->succeed($builder);
        $builder->appendPending();

        $this->assertSame('indexed', $photo->fresh()->index_status);
    }

    public function test_mutated_json_crop_is_rejected(): void
    {
        $product = Product::create(['sku' => 'JSON-B']);
        $photo = $this->storedPhoto($product, 'products/jb.jpg', $this->cropV1());
        $cropV2 = $this->cropV2();

        $builder = $this->partialBuilder();
        $this->succeed($builder, function () use ($photo, $cropV2) {
            \App\Models\ProductPhoto::whereKey($photo->id)->update(['crop' => json_encode($cropV2)]);
        });
        $builder->appendPending();

        $this->assertSame('indexing', $photo->fresh()->index_status);
        $this->assertSame($cropV2, $photo->fresh()->crop);
    }

    public function test_null_to_null_crop_matches(): void
    {
        $product = Product::create(['sku' => 'JSON-E']);
        $photo = $this->storedPhoto($product, 'products/je.jpg', null);

        $builder = $this->partialBuilder();
        $this->succeed($builder);
        $builder->appendPending();

        $this->assertNull($photo->fresh()->crop);
        $this->assertSame('indexed', $photo->fresh()->index_status);
    }

    public function test_null_to_json_crop_is_rejected(): void
    {
        $product = Product::create(['sku' => 'JSON-F1']);
        $photo = $this->storedPhoto($product, 'products/jf1.jpg', null);
        $cropV2 = $this->cropV2();

        $builder = $this->partialBuilder();
        $this->succeed($builder, function () use ($photo, $cropV2) {
            \App\Models\ProductPhoto::whereKey($photo->id)->update(['crop' => json_encode($cropV2)]);
        });
        $builder->appendPending();

        $this->assertSame($cropV2, $photo->fresh()->crop);
        $this->assertSame('indexing', $photo->fresh()->index_status);
    }

    public function test_json_to_null_crop_is_rejected(): void
    {
        $product = Product::create(['sku' => 'JSON-F2']);
        $photo = $this->storedPhoto($product, 'products/jf2.jpg', $this->cropV1());

        $builder = $this->partialBuilder();
        $this->succeed($builder, function () use ($photo) {
            \App\Models\ProductPhoto::whereKey($photo->id)->update(['crop' => null]);
        });
        $builder->appendPending();

        $this->assertNull($photo->fresh()->crop);
        $this->assertSame('indexing', $photo->fresh()->index_status);
    }

    private function storedFeedback(Product $product, string $name, ?array $crop): \App\Models\SearchFeedback
    {
        Storage::fake('local');
        Storage::disk('local')->put($name, 'fake-bytes');
        $admin = \App\Models\User::factory()->create(['role' => 'admin']);

        return \App\Models\SearchFeedback::create([
            'user_id' => $admin->id, 'confirmed_product_id' => $product->id,
            'predicted_sku' => $product->sku, 'confirmed_sku' => $product->sku,
            'disk' => 'local', 'query_image_path' => $name,
            'photo_hash' => str_repeat('e', 64), 'dhash' => str_repeat('5', 16),
            'crop' => $crop, 'training_status' => 'verified', 'reference_eligible' => true,
        ]);
    }

    public function test_unchanged_feedback_json_crop_stamps(): void
    {
        $product = Product::create(['sku' => 'JSON-C']);
        $row = $this->storedFeedback($product, 'verified-search/jc.webp', $this->cropV1());

        $builder = $this->partialBuilder();
        $this->succeed($builder);
        $builder->appendPending();

        $this->assertNotNull($row->fresh()->indexed_at);
    }

    public function test_mutated_feedback_json_crop_is_rejected(): void
    {
        $product = Product::create(['sku' => 'JSON-D']);
        $row = $this->storedFeedback($product, 'verified-search/jd.webp', $this->cropV1());
        $cropV2 = $this->cropV2();

        $builder = $this->partialBuilder();
        $this->succeed($builder, function () use ($row, $cropV2) {
            \App\Models\SearchFeedback::whereKey($row->id)->update(['crop' => json_encode($cropV2)]);
        });
        $builder->appendPending();

        $this->assertNull($row->fresh()->indexed_at);
        $this->assertSame($cropV2, $row->fresh()->crop);
    }

    public function test_null_feedback_crop_stamps(): void
    {
        $product = Product::create(['sku' => 'JSON-E-FB']);
        $row = $this->storedFeedback($product, 'verified-search/je.webp', null);

        $builder = $this->partialBuilder();
        $this->succeed($builder);
        $builder->appendPending();

        $this->assertNotNull($row->fresh()->indexed_at);
    }
}
