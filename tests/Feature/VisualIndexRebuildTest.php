<?php

namespace Tests\Feature;

use App\Imports\ProductsImport;
use App\Jobs\IndexVisualReference;
use App\Jobs\RebuildVisualIndex;
use App\Models\Product;
use App\Models\ProductAttribute;
use App\Models\SearchFeedback;
use App\Models\User;
use App\Services\ProductAttributes;
use App\Services\VisualIndexBuilder;
use App\Services\VisualIndexLifecycle;
use App\Services\VisualReferenceExporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class VisualIndexRebuildTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    private function servedPhoto(Product $product, string $status = 'indexed')
    {
        return $product->photos()->create(['path' => 'products/a.jpg', 'disk' => 'public', 'index_status' => $status]);
    }

    private function rebuildBuilder(): VisualIndexBuilder
    {
        return \Mockery::mock(VisualIndexBuilder::class)->makePartial();
    }

    /**
     * Backdate a row so it unambiguously predates any snapshot boundary
     * taken later in the test (production rows are hours/days old; without
     * this, same-second timestamps would be conservatively excluded).
     */
    private function ageRow($model): void
    {
        $model->newQuery()->whereKey($model->getKey())->update([
            'created_at' => now()->subHour(), 'updated_at' => now()->subHour(),
        ]);
    }

    private function uploadOnePhoto(Product $product): void
    {
        config(['retrieval.driver' => 'faiss']);
        Storage::fake('public');
        $file = UploadedFile::fake()->image('a.jpg', 120, 100);
        $bytes = base64_encode(file_get_contents($file->getRealPath()));
        Http::fake([rtrim(config('services.ai.url'), '/').'/prepare' => Http::sequence()->push(
            ['status' => 'optimized', 'extension' => 'jpg', 'master' => $bytes, 'catalog' => $bytes, 'thumbnail' => $bytes]
        )]);

        $this->actingAs($this->admin())
            ->postJson(route('admin.products.photo.store', $product), [
                'images' => [$file], 'selection_sources' => ['full'],
            ])->assertOk();
    }

    public function test_upload_new_photo_uses_incremental_only(): void
    {
        Queue::fake();
        $product = Product::create(['sku' => 'SKU123']);

        $this->uploadOnePhoto($product);

        Queue::assertPushed(IndexVisualReference::class, 1);
        Queue::assertNotPushed(RebuildVisualIndex::class);
        $this->assertSame(0, app(VisualIndexLifecycle::class)->dirtyRevision());
    }

    public function test_delete_indexed_photo_schedules_rebuild(): void
    {
        Queue::fake();
        Storage::fake('public');
        $product = Product::create(['sku' => 'SKU123']);
        $photo = $this->servedPhoto($product);

        $this->actingAs($this->admin())->delete(route('admin.photos.destroy', $photo))->assertRedirect();

        Queue::assertPushed(RebuildVisualIndex::class, 1);
        $this->assertGreaterThan(0, app(VisualIndexLifecycle::class)->dirtyRevision());
        $this->assertSame("photo {$photo->id} deleted; full rebuild required", Cache::get('visual-index-rebuild-required'));
        $this->assertSame(0, $product->photos()->count());
    }

    public function test_delete_pending_photo_schedules_nothing(): void
    {
        Queue::fake();
        Storage::fake('public');
        Cache::forget('visual-index-rebuild-required');
        $product = Product::create(['sku' => 'SKU123']);
        $photo = $product->photos()->create(['path' => 'products/a.jpg', 'disk' => 'public', 'index_status' => 'pending']);

        $this->actingAs($this->admin())->delete(route('admin.photos.destroy', $photo))->assertRedirect();

        Queue::assertNotPushed(RebuildVisualIndex::class);
        $this->assertSame(0, app(VisualIndexLifecycle::class)->dirtyRevision());
    }

    public function test_edit_sku_of_indexed_product_schedules_rebuild(): void
    {
        Queue::fake();
        $product = Product::create(['sku' => 'SKU123', 'description' => 'Obeng']);
        $this->servedPhoto($product);

        $this->actingAs($this->admin())->put(route('admin.products.update', $product), [
            'sku' => 'SKU456', 'description' => 'Obeng',
        ])->assertRedirect();

        Queue::assertPushed(RebuildVisualIndex::class, 1);
        $this->assertSame('rebuild-required', $product->photos()->sole()->fresh()->index_status);
    }

    public function test_edit_description_of_indexed_product_schedules_rebuild(): void
    {
        Queue::fake();
        $product = Product::create(['sku' => 'SKU123', 'description' => 'Obeng plus']);
        $this->servedPhoto($product);

        $this->actingAs($this->admin())->put(route('admin.products.update', $product), [
            'sku' => 'SKU123', 'description' => 'Obeng minus',
        ])->assertRedirect();

        Queue::assertPushed(RebuildVisualIndex::class, 1);
        $this->assertSame('rebuild-required', $product->photos()->sole()->fresh()->index_status);
    }

    public function test_save_product_unchanged_schedules_nothing(): void
    {
        Queue::fake();
        $product = Product::create(['sku' => 'SKU123', 'description' => 'Obeng']);
        $this->servedPhoto($product);

        $this->actingAs($this->admin())->put(route('admin.products.update', $product), [
            'sku' => 'SKU123', 'description' => 'Obeng',
        ])->assertRedirect();

        Queue::assertNotPushed(RebuildVisualIndex::class);
        $this->assertSame(0, app(VisualIndexLifecycle::class)->dirtyRevision());
        $this->assertSame('indexed', $product->photos()->sole()->fresh()->index_status);
    }

    public function test_import_changed_description_of_indexed_product_schedules_rebuild(): void
    {
        Queue::fake();
        $product = Product::create(['sku' => 'IMP-1', 'description' => 'Deskripsi lama']);
        $this->servedPhoto($product);

        (new ProductsImport)->collection(new Collection([['sku' => 'IMP-1', 'desc' => 'Deskripsi baru']]));

        $this->assertSame('Deskripsi baru', $product->fresh()->description);
        $this->assertSame('rebuild-required', $product->photos()->sole()->fresh()->index_status);
        Queue::assertPushed(RebuildVisualIndex::class, 1);
    }

    public function test_import_many_changed_products_coalesces_to_one_rebuild(): void
    {
        Queue::fake();
        foreach (['IMP-A', 'IMP-B', 'IMP-C'] as $sku) {
            $this->servedPhoto(Product::create(['sku' => $sku, 'description' => 'Lama']));
        }

        (new ProductsImport)->collection(new Collection([
            ['sku' => 'IMP-A', 'desc' => 'Baru A'],
            ['sku' => 'IMP-B', 'desc' => 'Baru B'],
            ['sku' => 'IMP-C', 'desc' => 'Baru C'],
        ]));

        Queue::assertPushed(RebuildVisualIndex::class, 1);
        $this->assertSame(3, Product::query()->whereHas('photos', fn ($query) => $query->where('index_status', 'rebuild-required'))->count());
    }

    public function test_import_identical_descriptions_schedules_nothing(): void
    {
        Queue::fake();
        $product = Product::create(['sku' => 'IMP-1', 'description' => 'Sama']);
        $this->servedPhoto($product);

        (new ProductsImport)->collection(new Collection([['sku' => 'IMP-1', 'desc' => 'Sama']]));

        Queue::assertNotPushed(RebuildVisualIndex::class);
        $this->assertSame(0, app(VisualIndexLifecycle::class)->dirtyRevision());
        $this->assertSame('indexed', $product->photos()->sole()->fresh()->index_status);
    }

    public function test_indexed_crop_change_schedules_rebuild_not_incremental(): void
    {
        Queue::fake();
        $product = Product::create(['sku' => 'REF-1']);
        $photo = $product->photos()->create([
            'path' => 'products/a.jpg', 'crop' => ['x' => 0.1, 'y' => 0.1, 'width' => 0.6, 'height' => 0.6],
            'selection_source' => 'manual', 'selection_verified' => true, 'index_status' => 'indexed',
        ]);

        $this->actingAs($this->admin())->put(route('admin.photos.selection.update', $photo), [
            'crop_json' => json_encode(['x' => 0.05, 'y' => 0.05, 'width' => 0.9, 'height' => 0.9]),
            'selection_source' => 'manual',
        ])->assertRedirect();

        Queue::assertPushed(RebuildVisualIndex::class, 1);
        Queue::assertNotPushed(IndexVisualReference::class);
        $this->assertSame('rebuild-required', $photo->fresh()->index_status);
    }

    public function test_identical_crop_schedules_nothing(): void
    {
        Queue::fake();
        $product = Product::create(['sku' => 'REF-1']);
        $crop = ['x' => 0.1, 'y' => 0.1, 'width' => 0.6, 'height' => 0.6];
        $photo = $product->photos()->create([
            'path' => 'products/a.jpg', 'crop' => $crop,
            'selection_source' => 'manual', 'selection_verified' => true, 'index_status' => 'indexed',
        ]);

        $this->actingAs($this->admin())->put(route('admin.photos.selection.update', $photo), [
            'crop_json' => json_encode($crop), 'selection_source' => 'manual',
        ])->assertRedirect();

        Queue::assertNotPushed(RebuildVisualIndex::class);
        Queue::assertNotPushed(IndexVisualReference::class);
        $this->assertSame('indexed', $photo->fresh()->index_status);
    }

    public function test_verification_only_change_schedules_nothing(): void
    {
        Queue::fake();
        $product = Product::create(['sku' => 'REF-1']);
        $crop = ['x' => 0.1, 'y' => 0.1, 'width' => 0.6, 'height' => 0.6];
        $photo = $product->photos()->create([
            'path' => 'products/a.jpg', 'crop' => $crop,
            'selection_source' => 'manual', 'selection_verified' => false, 'index_status' => 'indexed',
        ]);

        $this->actingAs($this->admin())->put(route('admin.photos.selection.update', $photo), [
            'crop_json' => json_encode($crop), 'selection_source' => 'manual',
        ])->assertRedirect();

        Queue::assertNotPushed(RebuildVisualIndex::class);
        Queue::assertNotPushed(IndexVisualReference::class);
        $this->assertSame('indexed', $photo->fresh()->index_status);
    }

    public function test_close_together_mutations_coalesce_to_one_job(): void
    {
        Queue::fake();
        Storage::fake('public');
        $product = Product::create(['sku' => 'SKU123', 'description' => 'Obeng']);
        $photo = $this->servedPhoto($product);
        $other = $product->photos()->create(['path' => 'products/b.jpg', 'disk' => 'public', 'index_status' => 'indexed']);

        // delete + metadata edit + crop edit in rapid succession.
        $this->actingAs($this->admin())->delete(route('admin.photos.destroy', $photo))->assertRedirect();
        $this->actingAs($this->admin())->put(route('admin.products.update', $product), [
            'sku' => 'SKU123', 'description' => 'Obeng besar',
        ])->assertRedirect();
        $this->actingAs($this->admin())->put(route('admin.photos.selection.update', $other), [
            'crop_json' => json_encode(['x' => 0.05, 'y' => 0.05, 'width' => 0.9, 'height' => 0.9]),
            'selection_source' => 'manual',
        ])->assertRedirect();

        Queue::assertPushed(RebuildVisualIndex::class, 1);
        $this->assertGreaterThan(0, app(VisualIndexLifecycle::class)->dirtyRevision());
    }

    public function test_mutation_during_run_schedules_follow_up(): void
    {
        Queue::fake();
        $product = Product::create(['sku' => 'SKU123']);
        $photo = $product->photos()->create(['path' => 'products/a.jpg', 'disk' => 'public', 'index_status' => 'rebuild-required']);
        $this->ageRow($photo->fresh());
        $other = $product->photos()->create(['path' => 'products/b.jpg', 'disk' => 'public', 'index_status' => 'indexed']);
        $this->ageRow($other->fresh());
        app(VisualIndexLifecycle::class)->markDirty('photo 1 deleted; full rebuild required');

        $builder = $this->rebuildBuilder();
        $builder->shouldReceive('acquireLock')->once()->andReturn(true);
        $builder->shouldReceive('pruneStaleWorkspaces')->once()->andReturn(0);
        $builder->shouldReceive('exportDataset')->once()->andReturn(2);
        $builder->shouldReceive('rebuildFull')->once()->andReturnUsing(function () use ($other) {
            // A new mutation lands while the build is running, AFTER the
            // snapshot was taken: fresh updated_at, must stay dirty.
            $other->update(['crop' => ['x' => 0.05, 'y' => 0.05, 'width' => 0.9, 'height' => 0.9], 'index_status' => 'rebuild-required']);
            app(VisualIndexLifecycle::class)->markDirty('photo 2 crop changed; full rebuild required');

            return ['added' => 2, 'skipped' => 0, 'generation' => 'generation-x'];
        });
        $builder->shouldReceive('releaseLock')->once();

        (new RebuildVisualIndex)->handle($builder, app(VisualIndexLifecycle::class));

        // The mid-build mutation survived (initial + mid-build dispatches;
        // the follow-up collapses into the already-queued job).
        Queue::assertPushed(RebuildVisualIndex::class, 2);
        $this->assertGreaterThan(0, app(VisualIndexLifecycle::class)->dirtyRevision());
        // Covered by the snapshot: reconciled. Mutated mid-build: untouched.
        $this->assertSame('indexed', $photo->fresh()->index_status);
        $this->assertSame('rebuild-required', $other->fresh()->index_status);

        // Follow-up rebuild covers the mutation: everything reconciles.
        $this->ageRow($other->fresh());
        $followUp = $this->rebuildBuilder();
        $followUp->shouldReceive('acquireLock')->once()->andReturn(true);
        $followUp->shouldReceive('pruneStaleWorkspaces')->once()->andReturn(0);
        $followUp->shouldReceive('exportDataset')->once()->andReturn(2);
        $followUp->shouldReceive('rebuildFull')->once()->andReturn(['added' => 0, 'skipped' => 2, 'generation' => 'generation-y']);
        $followUp->shouldReceive('releaseLock')->once();

        (new RebuildVisualIndex)->handle($followUp, app(VisualIndexLifecycle::class));

        $this->assertSame('indexed', $other->fresh()->index_status);
        $this->assertSame(0, app(VisualIndexLifecycle::class)->dirtyRevision());
    }

    public function test_successful_rebuild_reconciles_everything(): void
    {
        Queue::fake();
        Storage::fake('local');
        $admin = $this->admin();
        $product = Product::create(['sku' => 'SKU123']);
        $photo = $product->photos()->create(['path' => 'products/a.jpg', 'disk' => 'public', 'index_status' => 'rebuild-required']);
        $failed = $product->photos()->create(['path' => 'products/b.jpg', 'disk' => 'public', 'index_status' => 'failed']);
        $feedback = SearchFeedback::create([
            'user_id' => $admin->id, 'confirmed_product_id' => $product->id,
            'predicted_sku' => 'SKU123', 'confirmed_sku' => 'SKU123',
            'disk' => 'local', 'query_image_path' => 'verified-search/q.webp',
            'photo_hash' => str_repeat('a', 64), 'dhash' => str_repeat('0', 16),
            'training_status' => 'verified', 'reference_eligible' => true,
        ]);
        $this->ageRow($photo->fresh());
        $this->ageRow($failed->fresh());
        $this->ageRow($feedback->fresh());
        $lifecycle = app(VisualIndexLifecycle::class);
        $lifecycle->markDirty('photo 1 deleted; full rebuild required');
        $lifecycle->failConcise('previous failure');

        $builder = $this->rebuildBuilder();
        $builder->shouldReceive('acquireLock')->once()->andReturn(true);
        $builder->shouldReceive('pruneStaleWorkspaces')->once()->andReturn(0);
        $builder->shouldReceive('exportDataset')->once()->andReturn(2);
        $builder->shouldReceive('rebuildFull')->once()->andReturn(['added' => 2, 'skipped' => 0, 'generation' => 'generation-new']);
        $builder->shouldReceive('releaseLock')->once();

        (new RebuildVisualIndex)->handle($builder, $lifecycle);

        $this->assertSame('indexed', $photo->fresh()->index_status);
        $this->assertSame('indexed', $failed->fresh()->index_status);
        $this->assertNotNull($feedback->fresh()->indexed_at);
        $this->assertSame(0, $lifecycle->dirtyRevision());
        $this->assertNull(Cache::get('visual-index-rebuild-required'));
        $this->assertNull($lifecycle->failure());
        // Only the initial schedule; success needs no follow-up.
        Queue::assertPushed(RebuildVisualIndex::class, 1);
    }

    public function test_failed_rebuild_keeps_dirty_and_stores_concise_failure(): void
    {
        Queue::fake();
        $product = Product::create(['sku' => 'SKU123']);
        $photo = $product->photos()->create(['path' => 'products/a.jpg', 'disk' => 'public', 'index_status' => 'rebuild-required']);
        $feedback = SearchFeedback::create([
            'user_id' => $this->admin()->id, 'confirmed_product_id' => $product->id,
            'predicted_sku' => 'SKU123', 'confirmed_sku' => 'SKU123',
            'disk' => 'local', 'query_image_path' => 'verified-search/q.webp',
            'photo_hash' => str_repeat('a', 64), 'dhash' => str_repeat('0', 16),
            'training_status' => 'verified', 'reference_eligible' => true,
        ]);
        app(VisualIndexLifecycle::class)->markDirty('photo 1 deleted; full rebuild required');

        $builder = $this->rebuildBuilder();
        $builder->shouldReceive('acquireLock')->once()->andReturn(true);
        $builder->shouldReceive('pruneStaleWorkspaces')->once()->andReturn(0);
        $builder->shouldReceive('exportDataset')->once()->andReturn(1);
        $builder->shouldReceive('rebuildFull')->once()->andThrow(
            new \RuntimeException("Index rebuild failed; Traceback (most recent call last):\n".str_repeat('x', 1000)));
        $builder->shouldReceive('releaseLock')->once();

        try {
            (new RebuildVisualIndex)->handle($builder, app(VisualIndexLifecycle::class));
            $this->fail('Expected the build exception to propagate for queue retry.');
        } catch (\RuntimeException) {
        }

        $this->assertGreaterThan(0, app(VisualIndexLifecycle::class)->dirtyRevision());
        $failure = app(VisualIndexLifecycle::class)->failure();
        $this->assertNotNull($failure);
        $this->assertLessThanOrEqual(200, mb_strlen($failure));
        $this->assertStringNotContainsString('Traceback', $failure);
        $this->assertSame('rebuild-required', $photo->fresh()->index_status);
        $this->assertNull($feedback->fresh()->indexed_at);
    }

    public function test_redundant_rebuild_run_is_a_quiet_noop(): void
    {
        $builder = $this->rebuildBuilder();
        $builder->shouldReceive('acquireLock')->once()->andReturn(true);
        $builder->shouldNotReceive('exportDataset');
        $builder->shouldNotReceive('rebuildFull');
        $builder->shouldReceive('releaseLock')->once();

        (new RebuildVisualIndex)->handle($builder, app(VisualIndexLifecycle::class));

        $this->assertSame(0, app(VisualIndexLifecycle::class)->dirtyRevision());
    }

    public function test_lock_contention_releases_for_retry(): void
    {
        Queue::fake();
        $product = Product::create(['sku' => 'SKU123']);
        $product->photos()->create(['path' => 'products/a.jpg', 'disk' => 'public', 'index_status' => 'rebuild-required']);
        app(VisualIndexLifecycle::class)->markDirty('photo 1 deleted; full rebuild required');
        $builder = $this->rebuildBuilder();
        $builder->shouldReceive('acquireLock')->once()->andReturn(false);
        $builder->shouldNotReceive('exportDataset');
        $builder->shouldNotReceive('rebuildFull');
        $job = new RebuildVisualIndex;
        // Stand-in for the queue worker's job record.
        $job->job = new class
        {
            public mixed $delay = null;

            public function release($delay = 0): void
            {
                $this->delay = $delay;
            }
        };
        $job->handle($builder, app(VisualIndexLifecycle::class));

        $this->assertSame(300, $job->job->delay);
        $this->assertGreaterThan(0, app(VisualIndexLifecycle::class)->dirtyRevision());
    }

    public function test_manual_build_captures_revision_before_snapshot(): void
    {
        Queue::fake();
        Storage::fake('local');
        Storage::fake('public');
        $product = Product::create(['sku' => 'SKU123']);
        $photo = $product->photos()->create(['path' => 'products/a.jpg', 'disk' => 'public', 'index_status' => 'rebuild-required']);
        $this->ageRow($photo->fresh());
        $other = $product->photos()->create(['path' => 'products/b.jpg', 'disk' => 'public', 'index_status' => 'indexed']);
        $this->ageRow($other->fresh());
        // Revision 1 exists before the manual build starts.
        app(VisualIndexLifecycle::class)->markDirty('photo 1 deleted; full rebuild required');

        // The manual command shares the builder pipeline: mock only the
        // export + Python steps, keep real reconciliation.
        $builder = \Mockery::mock(VisualIndexBuilder::class)->makePartial();
        $builder->shouldReceive('exportDataset')->once()->andReturn(2);
        $builder->shouldReceive('rebuildFull')->once()->andReturnUsing(function () use ($other) {
            // Mutation lands mid-build, AFTER the snapshot: revision 2.
            $other->update(['crop' => ['x' => 0.2, 'y' => 0.2, 'width' => 0.5, 'height' => 0.5], 'index_status' => 'rebuild-required']);
            app(VisualIndexLifecycle::class)->markDirty('photo 2 crop changed; full rebuild required');

            return ['added' => 2, 'generation' => 'generation-manual'];
        });
        app()->instance(VisualIndexBuilder::class, $builder);

        $this->artisan('search:build-index')->assertSuccessful();

        $lifecycle = app(VisualIndexLifecycle::class);
        // Revision 1 is covered, revision 2 survives with a rebuild still
        // queued (initial + follow-up collapse into the single queued job).
        $this->assertSame(2, $lifecycle->dirtyRevision());
        Queue::assertPushed(RebuildVisualIndex::class, 1);
        $this->assertSame('indexed', $photo->fresh()->index_status);
        $this->assertSame('rebuild-required', $other->fresh()->index_status);
    }

    public function test_refresh_attributes_without_effective_change_schedules_nothing(): void
    {
        Queue::fake();
        $product = Product::create(['sku' => 'SKU123', 'description' => 'Obeng Plus PH2']);
        $product->attributes()->create(['key' => 'drive', 'value' => 'PH2', 'source' => ProductAttribute::SOURCE_MANUAL]);
        $photo = $product->photos()->create(['path' => 'products/a.jpg', 'disk' => 'public', 'index_status' => 'indexed']);
        $before = ProductAttributes::trustedForExport($product->fresh());

        $this->artisan('products:refresh-attributes', ['--sku' => 'SKU123'])->assertSuccessful();

        $this->assertSame($before, ProductAttributes::trustedForExport($product->fresh()));
        Queue::assertNotPushed(RebuildVisualIndex::class);
        $this->assertSame(0, app(VisualIndexLifecycle::class)->dirtyRevision());
        $this->assertSame('indexed', $photo->fresh()->index_status);
    }

    public function test_rebuild_job_timeout_and_retry_configuration(): void
    {
        $job = new RebuildVisualIndex;
        $this->assertSame(7200, $job->timeout);
        $this->assertSame(3, $job->tries);
        $this->assertSame([600, 1800, 3600], $job->backoff());

        // The reservation window must outlive the job cap with margin,
        // otherwise a still-running CPU rebuild gets picked up twice.
        config(['queue.connections.database.retry_after' => 7500]);
        $this->assertGreaterThan($job->timeout, (int) config('queue.connections.database.retry_after'));

        // The shipped example documents the production requirement.
        $example = file_get_contents(base_path('.env.example'));
        $this->assertStringContainsString('DB_QUEUE_RETRY_AFTER=7500', $example);
    }

    public function test_manual_build_command_uses_shared_pipeline(): void
    {
        Storage::fake('local');
        Storage::fake('public');
        $product = Product::create(['sku' => 'SKU123']);
        $photo = $product->photos()->create(['path' => 'products/a.jpg', 'disk' => 'public', 'index_status' => 'rebuild-required']);
        $this->ageRow($photo->fresh());

        $exporter = \Mockery::mock(VisualReferenceExporter::class);
        $exporter->shouldReceive('export')->once()->andReturn(1);
        app()->instance(VisualReferenceExporter::class, $exporter);
        $builder = \Mockery::mock(VisualIndexBuilder::class)->makePartial();
        $builder->shouldReceive('rebuildFull')->once()->andReturn(['added' => 1, 'generation' => 'generation-manual']);
        app()->instance(VisualIndexBuilder::class, $builder);

        $this->artisan('search:build-index')->assertSuccessful();

        // Same reconciliation as the automatic job path.
        $this->assertSame('indexed', $photo->fresh()->index_status);
        $this->assertSame(0, app(VisualIndexLifecycle::class)->dirtyRevision());
    }
}
