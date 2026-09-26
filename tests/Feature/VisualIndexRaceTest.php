<?php

namespace Tests\Feature;

use App\Jobs\RebuildVisualIndex;
use App\Models\Product;
use App\Services\VisualIndexBuilder;
use App\Services\VisualIndexLifecycle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Incremental race safety: CLAIMED STATE == EXPORTED STATE == STATE ALLOWED
 * TO BECOME INDEXED (content fingerprint, never timestamps alone).
 *
 * Simulates: pending → claim → reload → export → mutation mid-build → old
 * build completes. The stale completion must never mark the newer state
 * indexed. The expensive Python boundary is stubbed; no model downloads.
 * Claim/reload/snapshot/completion reconciliation always run for real.
 */
class VisualIndexRaceTest extends TestCase
{
    use RefreshDatabase;

    private function storedPhoto(Product $product, string $name): \App\Models\ProductPhoto
    {
        Storage::fake('public');
        Storage::disk('public')->put($name, 'fake-image-bytes');
        $photo = $product->photos()->create(['path' => $name, 'disk' => 'public', 'index_status' => 'pending']);

        return $photo;
    }

    private function partialBuilder(): VisualIndexBuilder
    {
        return \Mockery::mock(VisualIndexBuilder::class)->makePartial()
            ->shouldAllowMockingProtectedMethods();
    }

    public function test_mid_build_mutation_is_not_overwritten_by_stale_completion(): void
    {
        Queue::fake();
        $product = Product::create(['sku' => 'RACE-1']);
        $photo = $this->storedPhoto($product, 'products/race.jpg');

        $builder = $this->partialBuilder();
        // The "old" Python build runs while a crop mutation lands mid-build.
        $builder->shouldReceive('runBuilder')->once()->andReturnUsing(function () use ($photo) {
            $photo->update(['crop' => ['x' => 0.1, 'y' => 0.1, 'width' => 0.5, 'height' => 0.5],
                'selection_source' => 'manual', 'selection_verified' => true,
                'index_status' => 'rebuild-required']);
            app(VisualIndexLifecycle::class)->markDirty("photo {$photo->id} crop/selection changed; full rebuild required");

            return ['added' => 1, 'skipped' => 0, 'generation' => 'generation-race'];
        });

        $result = $builder->appendPending();

        $this->assertSame(['added' => 1, 'skipped' => 0, 'generation' => 'generation-race',
            'photos' => [$photo->id], 'feedback' => []], $result);
        // The stale completion MUST NOT flip rebuild-required → indexed.
        $this->assertSame('rebuild-required', $photo->fresh()->index_status);
        // The dirty revision survives so the full rebuild still heals it.
        $this->assertTrue(app(VisualIndexLifecycle::class)->isDirty());
        $this->assertNotNull(app(VisualIndexLifecycle::class)->dirtyReason());
        Queue::assertPushed(RebuildVisualIndex::class);
    }

    public function test_stale_pending_reset_is_not_marked_indexed(): void
    {
        Queue::fake();
        $product = Product::create(['sku' => 'RACE-2']);
        $photo = $this->storedPhoto($product, 'products/stale.jpg');

        $builder = $this->partialBuilder();
        // A stale controller write resets the row to pending mid-build
        // (loaded before the batch marked it indexing). Old bytes were
        // exported, so the row must stay pending for a retry — never indexed.
        // Query-builder write mirrors the controller's blind model update
        // (which always loads fresh in production, hence always dirty).
        $builder->shouldReceive('runBuilder')->once()->andReturnUsing(function () use ($photo) {
            \App\Models\ProductPhoto::whereKey($photo->id)->update(['index_status' => 'pending']);

            return ['added' => 1, 'skipped' => 0, 'generation' => 'generation-stale'];
        });

        $builder->appendPending();

        $this->assertSame('pending', $photo->fresh()->index_status);
    }

    public function test_normal_incremental_still_marks_indexed(): void
    {
        $product = Product::create(['sku' => 'RACE-OK']);
        $photo = $this->storedPhoto($product, 'products/ok.jpg');

        $builder = $this->partialBuilder();
        $builder->shouldReceive('runBuilder')->once()
            ->andReturn(['added' => 1, 'skipped' => 0, 'generation' => 'generation-ok']);

        $result = $builder->appendPending();

        $this->assertSame('indexed', $photo->fresh()->index_status);
        $this->assertSame([$photo->id], $result['photos']);
    }

    public function test_failed_build_leaves_retryable_state_never_indexed(): void
    {
        $product = Product::create(['sku' => 'RACE-FAIL']);
        $photo = $this->storedPhoto($product, 'products/fail.jpg');

        $builder = $this->partialBuilder();
        $builder->shouldReceive('runBuilder')->once()->andThrow(new \RuntimeException('boom'));

        try {
            $builder->appendPending();
            $this->fail('Expected RuntimeException');
        } catch (\RuntimeException) {
        }

        // Retryable, never indexed, never stuck in indexing.
        $this->assertSame('failed', $photo->fresh()->index_status);
    }

    public function test_rebuild_required_row_with_missing_file_is_left_untouched(): void
    {
        $product = Product::create(['sku' => 'RACE-EXPORT']);
        // Missing file + already awaiting a full rebuild: the batch must
        // filter it out before any export attempt (no build, no status flip).
        $photo = $product->photos()->create(['path' => 'products/missing.jpg', 'disk' => 'public', 'index_status' => 'rebuild-required']);
        Storage::fake('public');

        $builder = $this->partialBuilder();
        $builder->shouldReceive('runBuilder')->never();

        $result = $builder->appendPending();

        $this->assertSame(null, $result['generation']);
        $this->assertSame('rebuild-required', $photo->fresh()->index_status);
    }

    public function test_pre_claim_mutation_is_exported_as_current_and_indexed(): void
    {
        // An unserved-at-load writer commits V2 (staying pending, no dirty
        // revision — exactly like the real writers). The batch must export
        // the CURRENT database state (V2, never the stale SELECT object) and
        // may legitimately complete it to indexed.
        $product = Product::create(['sku' => 'RACE-STALE-OBJ']);
        $photo = $this->storedPhoto($product, 'products/stale-obj.jpg');
        $cropV2 = ['x' => 0.2, 'y' => 0.2, 'width' => 0.4, 'height' => 0.4];
        \App\Models\ProductPhoto::whereKey($photo->id)->update([
            'crop' => json_encode($cropV2), 'selection_source' => 'manual', 'selection_verified' => true,
        ]);

        $builder = $this->partialBuilder();
        // Capture what the worker actually exported: the workspace still
        // exists while the (stubbed) builder boundary runs.
        $exported = null;
        $builder->shouldReceive('runBuilder')->once()->andReturnUsing(function ($workspace) use ($product, $photo, &$exported) {
            $sidecar = $workspace.DIRECTORY_SEPARATOR.$product->sku.DIRECTORY_SEPARATOR.$photo->id.'.json';
            $exported = json_decode(file_get_contents($sidecar), true);

            return ['added' => 1, 'skipped' => 0, 'generation' => 'generation-current'];
        });

        $builder->appendPending();

        $this->assertSame($cropV2, $exported['crop']);
        $this->assertSame('manual', $exported['selection_source']);
        $this->assertSame('indexed', $photo->fresh()->index_status);
    }

    public function test_same_timestamp_mutation_is_not_marked_indexed(): void
    {
        // Timestamp-only CAS would collide here on second-precision storage:
        // force the mutation to carry the exact claim timestamp while
        // changing index-affecting content (status stays indexing, isolating
        // the timestamp dimension). Content identity must still reject it.
        $product = Product::create(['sku' => 'RACE-SAME-TS']);
        $photo = $this->storedPhoto($product, 'products/same-ts.jpg');

        $builder = $this->partialBuilder();
        $builder->shouldReceive('runBuilder')->once()->andReturnUsing(function () use ($photo) {
            $stamp = $photo->fresh()->updated_at->format('Y-m-d H:i:s');
            \App\Models\ProductPhoto::whereKey($photo->id)->update([
                'crop' => json_encode(['x' => 0.3, 'y' => 0.3, 'width' => 0.3, 'height' => 0.3]),
                'updated_at' => $stamp,
            ]);

            return ['added' => 1, 'skipped' => 0, 'generation' => 'generation-same-ts'];
        });

        $builder->appendPending();

        // Newer content survives as retryable indexing — never indexed.
        $this->assertSame('indexing', $photo->fresh()->index_status);
    }

    public function test_product_description_mutation_is_not_marked_indexed(): void
    {
        // Product metadata is part of the exported reference. A description
        // change on an unserved photo flips no status and bumps no revision
        // (mirroring the real writer, which only invalidates served rows) —
        // the stale completion must still refuse to mark it indexed.
        $product = Product::create(['sku' => 'RACE-META', 'description' => 'Obeng plus V1']);
        $photo = $this->storedPhoto($product, 'products/meta.jpg');

        $builder = $this->partialBuilder();
        $builder->shouldReceive('runBuilder')->once()->andReturnUsing(function () use ($product) {
            $product->update(['description' => 'Obeng minus V2']);

            return ['added' => 1, 'skipped' => 0, 'generation' => 'generation-meta'];
        });

        $builder->appendPending();

        $this->assertSame('Obeng minus V2', $product->fresh()->description);
        $this->assertSame('indexing', $photo->fresh()->index_status);
    }

    public function test_failure_after_mutation_keeps_rebuild_required(): void
    {
        Queue::fake();
        $product = Product::create(['sku' => 'RACE-FAIL-MUT']);
        $photo = $this->storedPhoto($product, 'products/fail-mut.jpg');

        $builder = $this->partialBuilder();
        $builder->shouldReceive('runBuilder')->once()->andReturnUsing(function () use ($photo) {
            \App\Models\ProductPhoto::whereKey($photo->id)->update(['index_status' => 'rebuild-required']);
            app(VisualIndexLifecycle::class)->markDirty("photo {$photo->id} crop/selection changed; full rebuild required");
            throw new \RuntimeException('generic inference failure');
        });

        try {
            $builder->appendPending();
            $this->fail('Expected RuntimeException');
        } catch (\RuntimeException) {
        }

        // The generic failure path must not downgrade rebuild-required.
        $this->assertSame('rebuild-required', $photo->fresh()->index_status);
        $this->assertTrue(app(VisualIndexLifecycle::class)->isDirty());
    }

    public function test_delete_during_build_never_resurrects(): void
    {
        $product = Product::create(['sku' => 'RACE-DEL']);
        $photo = $this->storedPhoto($product, 'products/del.jpg');

        $builder = $this->partialBuilder();
        $builder->shouldReceive('runBuilder')->once()->andReturnUsing(function () use ($photo) {
            $photo->forceDelete();

            return ['added' => 1, 'skipped' => 0, 'generation' => 'generation-del'];
        });

        // Must not throw; completion matches zero rows.
        $builder->appendPending();

        $this->assertNull(\App\Models\ProductPhoto::find($photo->id));
    }

    public function test_no_flip_content_mutation_during_build_is_not_indexed(): void
    {
        // Test A core: a writer changes reference content WITHOUT flipping
        // status (stays indexing) while the build runs — the exact case a
        // status-only guard misses. Only two valid outcomes: reject (mutation
        // first) or invalidate-after (completion first). Never V2+indexed(V1).
        $product = Product::create(['sku' => 'RACE-NOFLIP']);
        $photo = $this->storedPhoto($product, 'products/noflip.jpg');

        $builder = $this->partialBuilder();
        $builder->shouldReceive('runBuilder')->once()->andReturnUsing(function () use ($photo) {
            \App\Models\ProductPhoto::whereKey($photo->id)->update([
                'crop' => json_encode(['x' => 0.4, 'y' => 0.4, 'width' => 0.2, 'height' => 0.2]),
            ]);

            return ['added' => 1, 'skipped' => 0, 'generation' => 'generation-noflip'];
        });

        $builder->appendPending();

        $this->assertSame('indexing', $photo->fresh()->index_status);
    }

    public function test_completion_update_carries_atomic_state_guard(): void
    {
        // Anti-regression for §10's forbidden pattern: the final UPDATE that
        // marks indexed must compare the claimed photo-side state IN the same
        // statement (no unconditional bulk update). Inspects the real query
        // log of a successful run — deterministic, no timing involved.
        $product = Product::create(['sku' => 'RACE-SQL']);
        $photo = $this->storedPhoto($product, 'products/sql.jpg');

        $builder = $this->partialBuilder();
        $builder->shouldReceive('runBuilder')->once()
            ->andReturn(['added' => 1, 'skipped' => 0, 'generation' => 'generation-sql']);

        \Illuminate\Support\Facades\DB::enableQueryLog();
        try {
            $builder->appendPending();
        } finally {
            $queries = \Illuminate\Support\Facades\DB::getQueryLog();
            \Illuminate\Support\Facades\DB::disableQueryLog();
        }

        $completion = null;
        foreach ($queries as $entry) {
            $sql = is_array($entry) ? ($entry['query'] ?? '') : $entry->sql;
            if (str_contains($sql, '"index_status" = ?') && str_contains($sql, 'product_photos')) {
                $completion = $sql;
            }
        }
        $this->assertNotNull($completion, 'Expected a guarded product_photos completion UPDATE');
        foreach (['"index_status"', '"crop"', '"photo_hash"', '"selection_source"', '"path"', '"product_id"'] as $guard) {
            $this->assertStringContainsString($guard, $completion);
        }
        $this->assertSame('indexed', $photo->fresh()->index_status);
    }

    public function test_trusted_attribute_mutation_is_not_marked_indexed(): void
    {
        // Test C: trusted manual attributes feed the exported sidecar. A new
        // manual row mid-build changes the effective reference without
        // touching the photo row at all — completion must still refuse it.
        $product = Product::create(['sku' => 'RACE-TRUSTED', 'description' => 'Obeng']);
        $photo = $this->storedPhoto($product, 'products/trusted.jpg');

        $builder = $this->partialBuilder();
        $builder->shouldReceive('runBuilder')->once()->andReturnUsing(function () use ($product) {
            $product->attributes()->create(['key' => 'color', 'value' => 'Merah', 'source' => \App\Models\ProductAttribute::SOURCE_MANUAL]);

            return ['added' => 1, 'skipped' => 0, 'generation' => 'generation-trusted'];
        });

        $builder->appendPending();

        $this->assertSame('indexing', $photo->fresh()->index_status);
    }

    public function test_failure_after_no_flip_mutation_keeps_retryable_state(): void
    {
        // Test D: generic builder failure after a status-preserving content
        // change — the failure handler owns only claimed V1, so it must not
        // park V2 as failed. Row stays retryable for the next run.
        $product = Product::create(['sku' => 'RACE-FAIL-NOFLIP']);
        $photo = $this->storedPhoto($product, 'products/fail-noflip.jpg');

        $builder = $this->partialBuilder();
        $builder->shouldReceive('runBuilder')->once()->andReturnUsing(function () use ($photo) {
            \App\Models\ProductPhoto::whereKey($photo->id)->update([
                'crop' => json_encode(['x' => 0.1, 'y' => 0.1, 'width' => 0.2, 'height' => 0.2]),
            ]);
            throw new \RuntimeException('generic inference failure');
        });

        try {
            $builder->appendPending();
            $this->fail('Expected RuntimeException');
        } catch (\RuntimeException) {
        }

        $this->assertSame('indexing', $photo->fresh()->index_status);
    }

    public function test_feedback_product_mutation_is_not_stamped(): void
    {
        // Test E: verified feedback whose product metadata changes mid-build
        // must not be stamped indexed_at with the stale published reference.
        Storage::fake('local');
        $admin = \App\Models\User::factory()->create(['role' => 'admin']);
        $product = Product::create(['sku' => 'RACE-FB', 'description' => 'Desc V1']);
        Storage::disk('local')->put('verified-search/fb.webp', 'fake-bytes');
        $row = \App\Models\SearchFeedback::create([
            'user_id' => $admin->id, 'confirmed_product_id' => $product->id,
            'predicted_sku' => 'RACE-FB', 'confirmed_sku' => 'RACE-FB',
            'disk' => 'local', 'query_image_path' => 'verified-search/fb.webp',
            'photo_hash' => str_repeat('c', 64), 'dhash' => str_repeat('3', 16),
            'training_status' => 'verified', 'reference_eligible' => true,
        ]);

        $builder = $this->partialBuilder();
        $builder->shouldReceive('runBuilder')->once()->andReturnUsing(function () use ($product) {
            $product->update(['description' => 'Desc V2']);

            return ['added' => 1, 'skipped' => 0, 'generation' => 'generation-fb-mut'];
        });

        $builder->appendPending();

        $this->assertNull($row->fresh()->indexed_at);
    }

    public function test_unchanged_feedback_is_stamped(): void
    {
        // Control for E: no mutation → verified feedback stamps normally.
        Storage::fake('local');
        $admin = \App\Models\User::factory()->create(['role' => 'admin']);
        $product = Product::create(['sku' => 'RACE-FB-OK']);
        Storage::disk('local')->put('verified-search/ok.webp', 'fake-bytes');
        $row = \App\Models\SearchFeedback::create([
            'user_id' => $admin->id, 'confirmed_product_id' => $product->id,
            'predicted_sku' => 'RACE-FB-OK', 'confirmed_sku' => 'RACE-FB-OK',
            'disk' => 'local', 'query_image_path' => 'verified-search/ok.webp',
            'photo_hash' => str_repeat('d', 64), 'dhash' => str_repeat('4', 16),
            'training_status' => 'verified', 'reference_eligible' => true,
        ]);

        $builder = $this->partialBuilder();
        $builder->shouldReceive('runBuilder')->once()
            ->andReturn(['added' => 1, 'skipped' => 0, 'generation' => 'generation-fb-ok']);

        $result = $builder->appendPending();

        $this->assertNotNull($row->fresh()->indexed_at);
        $this->assertSame([$row->id], $result['feedback']);
    }
}
