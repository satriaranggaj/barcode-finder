<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class BackfillSelectionTest extends TestCase
{
    use RefreshDatabase;

    private string $selectUrl;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        $this->selectUrl = rtrim(config('services.ai.url'), '/').'/select';
    }

    private function makePhoto(Product $product, string $name, ?array $crop = null): \App\Models\ProductPhoto
    {
        Storage::disk('public')->put("products/{$name}", 'image-bytes-'.$name);

        return $product->photos()->create(['path' => "products/{$name}", 'disk' => 'public', 'crop' => $crop]);
    }

    private function fakeSelect(array|int $box): void
    {
        $payload = is_int($box)
            ? Http::response([], $box)
            : ['boxes' => [$box], 'candidates' => [['box' => $box, 'source' => 'grabcut_foreground', 'score' => .5]], 'reason' => 'foreground_proposal'];
        Http::fake([$this->selectUrl => $payload]);
    }

    public function test_backfills_auto_selection_and_marks_pending(): void
    {
        $product = Product::create(['sku' => 'SKU123']);
        $photo = $this->makePhoto($product, 'a.jpg');
        $box = ['x' => .1, 'y' => .2, 'width' => .5, 'height' => .6];
        $this->fakeSelect($box);

        $this->artisan('products:backfill-images')->assertSuccessful();

        $photo = $photo->fresh();
        $this->assertEquals($box, $photo->crop);
        $this->assertSame('auto', $photo->selection_source);
        $this->assertFalse($photo->selection_verified);
        $this->assertSame('pending', $photo->index_status);
        $this->assertSame('manual_reference', $photo->source);
    }

    public function test_rerun_skips_processed_photos(): void
    {
        $product = Product::create(['sku' => 'SKU123']);
        $photo = $this->makePhoto($product, 'a.jpg');
        $this->fakeSelect(['x' => .1, 'y' => .2, 'width' => .5, 'height' => .6]);
        $this->artisan('products:backfill-images')->assertSuccessful();

        $this->fakeSelect(['x' => .3, 'y' => .3, 'width' => .4, 'height' => .4]);
        $this->artisan('products:backfill-images')->assertSuccessful();

        $this->assertEquals(['x' => .1, 'y' => .2, 'width' => .5, 'height' => .6], $photo->fresh()->crop);
    }

    public function test_force_reprocesses_photos(): void
    {
        $product = Product::create(['sku' => 'SKU123']);
        $photo = $this->makePhoto($product, 'a.jpg', ['x' => .1, 'y' => .2, 'width' => .5, 'height' => .6]);
        $this->fakeSelect(['x' => .3, 'y' => .3, 'width' => .4, 'height' => .4]);

        $this->artisan('products:backfill-images', ['--force' => true])->assertSuccessful();

        $this->assertEquals(['x' => .3, 'y' => .3, 'width' => .4, 'height' => .4], $photo->fresh()->crop);
    }

    public function test_dry_run_changes_nothing(): void
    {
        $product = Product::create(['sku' => 'SKU123']);
        $photo = $this->makePhoto($product, 'a.jpg');
        $this->fakeSelect(['x' => .1, 'y' => .2, 'width' => .5, 'height' => .6]);

        $this->artisan('products:backfill-images', ['--dry-run' => true])->assertSuccessful();

        $fresh = $photo->fresh();
        $this->assertNull($fresh->crop);
        $this->assertFalse($fresh->selection_verified);
        $this->assertSame('manual_reference', $fresh->source);
    }

    public function test_product_option_scopes_to_one_sku(): void
    {
        $one = Product::create(['sku' => 'SKU1']);
        $two = Product::create(['sku' => 'SKU2']);
        $photoOne = $this->makePhoto($one, 'one.jpg');
        $photoTwo = $this->makePhoto($two, 'two.jpg');
        $this->fakeSelect(['x' => .1, 'y' => .2, 'width' => .5, 'height' => .6]);

        $this->artisan('products:backfill-images', ['--product' => 'SKU1'])->assertSuccessful();

        $this->assertNotNull($photoOne->fresh()->crop);
        $this->assertNull($photoTwo->fresh()->crop);
    }

    public function test_unknown_product_fails_fast(): void
    {
        $this->artisan('products:backfill-images', ['--product' => 'NOPE'])->assertFailed();
    }

    public function test_ai_failure_continues_with_next_photo(): void
    {
        $product = Product::create(['sku' => 'SKU123']);
        $bad = $this->makePhoto($product, 'bad.jpg');
        $good = $this->makePhoto($product, 'good.jpg');
        Http::fake([$this->selectUrl => Http::sequence()
            ->push([], 500)
            ->push(['boxes' => [], 'candidates' => [['box' => ['x' => .1, 'y' => .2, 'width' => .5, 'height' => .6], 'source' => 'grabcut_foreground']], 'reason' => 'foreground_proposal'])]);

        $this->artisan('products:backfill-images')->assertFailed();

        $this->assertNull($bad->fresh()->crop);
        $this->assertNotNull($good->fresh()->crop);
    }

    public function test_empty_candidates_is_fallback_not_failure(): void
    {
        $product = Product::create(['sku' => 'SKU123']);
        $photo = $this->makePhoto($product, 'a.jpg');
        Http::fake([$this->selectUrl => ['boxes' => [], 'candidates' => [], 'reason' => 'foreground_uncertain']]);

        $this->artisan('products:backfill-images')->assertSuccessful();

        $this->assertNull($photo->fresh()->crop);
    }

    public function test_admin_review_filter_and_badges(): void
    {
        Storage::fake('public');
        $product = Product::create(['sku' => 'SKU123']);
        $verified = $this->makePhoto($product, 'v.jpg', ['x' => .1, 'y' => .1, 'width' => .5, 'height' => .5]);
        $verified->update(['selection_source' => 'manual', 'selection_verified' => true]);
        $auto = $this->makePhoto($product, 'a.jpg', ['x' => .1, 'y' => .1, 'width' => .5, 'height' => .5]);
        $auto->update(['selection_source' => 'auto', 'selection_verified' => false]);
        $failed = $this->makePhoto($product, 'f.jpg');
        $admin = User::factory()->create(['role' => 'admin']);

        $page = $this->actingAs($admin)->get(route('admin.products.show', $product));
        $page->assertOk();
        $page->assertSee('✓ Terverifikasi');
        $page->assertSee('belum diverifikasi');
        $page->assertSee('Tanpa seleksi');

        $filtered = $this->actingAs($admin)->get(route('admin.products.show', ['product' => $product->id, 'selection' => 'verified']));
        $filtered->assertOk();
        $filtered->assertSee('✓ Terverifikasi');
        $filtered->assertDontSee('Tanpa seleksi');

        $failedOnly = $this->actingAs($admin)->get(route('admin.products.show', ['product' => $product->id, 'selection' => 'failed']));
        $failedOnly->assertOk();
        $failedOnly->assertSee('Tanpa seleksi');
        $failedOnly->assertDontSee('✓ Terverifikasi');
    }
}
