<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\SearchFeedback;
use App\Models\User;
use App\Services\VisualIndexBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class VisualIndexBuilderTest extends TestCase
{
    use RefreshDatabase;

    public function test_is_rebuild_required_detects_signals(): void
    {
        $builder = new VisualIndexBuilder;
        $this->assertTrue($builder->isRebuildRequired('Pipeline/model changed; use --rebuild'));
        $this->assertTrue($builder->isRebuildRequired('Changed image/metadata: SKU123/1.jpg; use --rebuild'));
        $this->assertTrue($builder->isRebuildRequired('References deleted from dataset; use --rebuild'));
        $this->assertTrue($builder->isRebuildRequired('Preprocessing changed; rebuild index'));
        $this->assertFalse($builder->isRebuildRequired('Missing source image for photo 1.'));
        $this->assertFalse($builder->isRebuildRequired('Index append failed; connection refused'));
    }

    public function test_append_pending_noop_when_empty(): void
    {
        // No pending rows: returns early without spawning Python.
        $result = (new VisualIndexBuilder)->appendPending();

        $this->assertSame(null, $result['generation']);
        $this->assertSame([], $result['photos']);
    }

    public function test_append_pending_skips_bad_row_without_build(): void
    {
        Storage::fake('public');
        $product = Product::create(['sku' => 'SKU123']);
        // No real file behind products/missing.jpg: per-row export fails.
        $photo = $product->photos()->create(['path' => 'products/missing.jpg', 'disk' => 'public', 'index_status' => 'pending']);

        $result = (new VisualIndexBuilder)->appendPending();

        $this->assertSame(null, $result['generation']);
        $this->assertSame('failed', $photo->fresh()->index_status);
    }

    public function test_mark_all_indexed_respects_include_verified(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $product = Product::create(['sku' => 'SKU123']);
        $product->photos()->create(['path' => 'products/a.jpg', 'disk' => 'public', 'index_status' => 'failed']);
        $row = SearchFeedback::create([
            'user_id' => $admin->id, 'confirmed_product_id' => $product->id,
            'predicted_sku' => 'SKU123', 'confirmed_sku' => 'SKU123',
            'disk' => 'local', 'query_image_path' => 'verified-search/q.webp',
            'photo_hash' => str_repeat('a', 64), 'dhash' => str_repeat('0', 16),
            'training_status' => 'verified', 'reference_eligible' => true,
        ]);
        Cache::forever('visual-index-rebuild-required', 'stale');

        (new VisualIndexBuilder)->markAllIndexed(false);

        $this->assertSame('indexed', $product->photos()->sole()->fresh()->index_status);
        $this->assertNull($row->fresh()->indexed_at);
        $this->assertNull(Cache::get('visual-index-rebuild-required'));

        $row->update(['indexed_at' => null]);
        $product->photos()->sole()->update(['index_status' => 'failed']);
        Cache::forever('visual-index-rebuild-required', 'stale');

        (new VisualIndexBuilder)->markAllIndexed(true);

        $this->assertSame('indexed', $product->photos()->sole()->fresh()->index_status);
        $this->assertNotNull($row->fresh()->indexed_at);
    }

    public function test_rebuild_required_rows_do_not_block_batch(): void
    {
        Storage::fake('public');
        $product = Product::create(['sku' => 'SKU123']);
        $stuck = $product->photos()->create(['path' => 'products/stuck.jpg', 'disk' => 'public', 'index_status' => 'rebuild-required']);

        $result = (new VisualIndexBuilder)->appendPending();

        $this->assertSame(null, $result['generation']);
        // Stuck row waits for a full rebuild, untouched by incremental runs.
        $this->assertSame('rebuild-required', $stuck->fresh()->index_status);
    }

    public function test_changed_image_blame_parsing(): void
    {
        $builder = new VisualIndexBuilder;
        $this->assertSame(58, $builder->changedImagePhotoId('Changed image/metadata: 9047628/58.webp; use --rebuild'));
        $this->assertSame(7, $builder->changedImagePhotoId('prefix Changed image/metadata: SKU123/7.jpg; use --rebuild suffix'));
        $this->assertNull($builder->changedImagePhotoId('Changed image/metadata: SKU123/verified-9.webp; use --rebuild'));
        $this->assertNull($builder->changedImagePhotoId('Pipeline/model changed; use --rebuild'));
        $this->assertNull($builder->changedImagePhotoId('References deleted from dataset; use --rebuild'));
        $this->assertNull($builder->changedImagePhotoId('Missing source image for photo 1.'));
    }

    public function test_prune_ignores_non_uuid_and_fresh_but_removes_stale(): void
    {
        Storage::fake('local');
        $disk = Storage::disk('local');
        $disk->makeDirectory('index-builds/not-a-workspace');
        $disk->put('index-builds/not-a-workspace/file.txt', 'keep');
        $stale = '11111111-2222-3333-4444-555555555555';
        $fresh = 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee';
        $disk->makeDirectory("index-builds/{$stale}");
        $disk->put("index-builds/{$stale}/x.txt", 'old');
        // Backdate beyond the retention window (default 3 days).
        touch($disk->path("index-builds/{$stale}"), time() - 4 * 86400);
        $disk->makeDirectory("index-builds/{$fresh}");
        $disk->put("index-builds/{$fresh}/x.txt", 'new');

        $this->assertSame(1, (new VisualIndexBuilder)->pruneStaleWorkspaces());
        $this->assertTrue($disk->exists('index-builds/not-a-workspace/file.txt'));
        $this->assertFalse($disk->exists("index-builds/{$stale}"));
        $this->assertTrue($disk->exists("index-builds/{$fresh}/x.txt"));
    }

    public function test_prune_missing_base_returns_zero_without_throwing(): void
    {
        Storage::fake('local');

        $this->assertSame(0, (new VisualIndexBuilder)->pruneStaleWorkspaces());
    }
}
