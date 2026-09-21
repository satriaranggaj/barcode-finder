<?php

namespace Tests\Feature;

use App\Jobs\IndexVisualReference;
use App\Models\Product;
use App\Models\SearchFeedback;
use App\Models\User;
use App\Services\VisualIndexBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class IndexVisualReferenceTest extends TestCase
{
    use RefreshDatabase;

    private function fakeBuilder(): VisualIndexBuilder
    {
        return \Mockery::mock(VisualIndexBuilder::class)->makePartial();
    }

    public function test_upload_dispatches_indexing_job(): void
    {
        Queue::fake();
        config(['retrieval.driver' => 'faiss']);
        Storage::fake('public');
        $product = Product::create(['sku' => 'SKU123']);
        $file = UploadedFile::fake()->image('a.jpg', 120, 100);
        $bytes = base64_encode(file_get_contents($file->getRealPath()));
        Http::fake([rtrim(config('services.ai.url'), '/').'/prepare' => Http::sequence()->push(
            ['status' => 'optimized', 'extension' => 'jpg', 'master' => $bytes, 'catalog' => $bytes, 'thumbnail' => $bytes]
        )]);

        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->postJson(route('admin.products.photo.store', $product), [
                'images' => [$file], 'selection_sources' => ['full'],
            ])->assertOk();

        $photo = $product->photos()->sole();
        $this->assertSame('pending', $photo->index_status);
        Queue::assertPushed(IndexVisualReference::class, fn ($job) => $job->kind === 'photo' && $job->id === $photo->id);
    }

    public function test_eligible_confirmation_dispatches_but_ineligible_does_not(): void
    {
        Queue::fake();
        config(['retrieval.feedback_enabled' => true, 'retrieval.query_temp_disk' => 'local', 'retrieval.private_disk' => 'local']);
        Storage::fake('local');
        $user = User::factory()->create(['role' => 'admin']);
        $product = Product::create(['sku' => 'RIGHT']);
        foreach ([['blur' => 120, 'suffix' => 'a', 'dhash' => '0'], ['blur' => 5, 'suffix' => 'b', 'dhash' => '2']] as $case) {
            $image = UploadedFile::fake()->image('q-'.$case['suffix'].'.jpg', 300, 300);
            $token = app(\App\Services\SearchEvidence::class)->stage([
                'results' => [['rank' => 1, 'sku' => $product->sku, 'score' => 0.8]],
                'confidence' => 'high',
                'feedback' => [
                    'master' => base64_encode(file_get_contents($image->getRealPath())),
                    'photo_hash' => str_repeat($case['suffix'], 64), 'dhash' => str_repeat($case['dhash'], 16),
                    'blur' => $case['blur'], 'min_side' => 300, 'crop_pixels' => 90000, 'gray_std' => 45,
                ],
            ], $user->id);
            $this->assertNotNull($token);
            $this->actingAs($user)->post(route('search.feedback'), ['token' => $token, 'sku' => $product->sku])
                ->assertRedirect();
        }
        $eligible = SearchFeedback::where('reference_eligible', true)->sole();
        Queue::assertPushed(IndexVisualReference::class, 1);
        Queue::assertPushed(IndexVisualReference::class, fn ($job) => $job->kind === 'feedback' && $job->id === $eligible->id);
    }

    public function test_job_drains_batch_and_marks_indexed(): void
    {
        $product = Product::create(['sku' => 'SKU123']);
        $first = $product->photos()->create(['path' => 'products/a.jpg', 'disk' => 'public', 'index_status' => 'pending']);
        $second = $product->photos()->create(['path' => 'products/b.jpg', 'disk' => 'public', 'index_status' => 'failed']);
        $builder = $this->fakeBuilder();
        $builder->shouldReceive('acquireLock')->once()->andReturn(true);
        $builder->shouldReceive('pruneStaleWorkspaces')->once()->andReturn(0);
        $builder->shouldReceive('appendPending')->once()->andReturnUsing(function () use ($first, $second) {
            $first->update(['index_status' => 'indexed']);
            $second->update(['index_status' => 'indexed']);

            return ['added' => 2, 'skipped' => 0, 'generation' => 'generation-test', 'photos' => [$first->id, $second->id], 'feedback' => []];
        });
        $builder->shouldReceive('releaseLock')->once();

        (new IndexVisualReference('photo', $first->id))->handle($builder);

        $this->assertSame('indexed', $first->fresh()->index_status);
        $this->assertSame('indexed', $second->fresh()->index_status);
    }

    public function test_job_coalesces_photo_and_feedback_into_one_generation(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $product = Product::create(['sku' => 'SKU123']);
        $photo = $product->photos()->create(['path' => 'products/a.jpg', 'disk' => 'public', 'index_status' => 'pending']);
        $row = SearchFeedback::create([
            'user_id' => $admin->id, 'confirmed_product_id' => $product->id,
            'predicted_sku' => 'SKU123', 'confirmed_sku' => 'SKU123',
            'disk' => 'local', 'query_image_path' => 'verified-search/q.webp',
            'photo_hash' => str_repeat('b', 64), 'dhash' => str_repeat('1', 16),
            'training_status' => 'verified', 'reference_eligible' => true,
        ]);
        $builder = $this->fakeBuilder();
        $builder->shouldReceive('acquireLock')->once()->andReturn(true);
        $builder->shouldReceive('pruneStaleWorkspaces')->once()->andReturn(0);
        // Exactly ONE builder invocation drains the whole batch.
        $builder->shouldReceive('appendPending')->once()->andReturnUsing(function () use ($photo, $row) {
            $photo->update(['index_status' => 'indexed']);
            $row->update(['indexed_at' => now()]);

            return ['added' => 2, 'skipped' => 0, 'generation' => 'generation-coalesced',
                'photos' => [$photo->id], 'feedback' => [$row->id]];
        });
        $builder->shouldReceive('releaseLock')->once();

        (new IndexVisualReference('photo', $photo->id))->handle($builder);

        $this->assertSame('indexed', $photo->fresh()->index_status);
        $this->assertNotNull($row->fresh()->indexed_at);
    }

    public function test_job_rerun_is_idempotent_no_duplicate(): void
    {
        $product = Product::create(['sku' => 'SKU123']);
        $photo = $product->photos()->create(['path' => 'products/a.jpg', 'disk' => 'public', 'index_status' => 'indexed']);
        $builder = $this->fakeBuilder();
        $builder->shouldReceive('acquireLock')->twice()->andReturn(true, true);
        $builder->shouldReceive('pruneStaleWorkspaces')->twice()->andReturn(0, 0);
        $builder->shouldReceive('appendPending')->twice()->andReturn(
            ['added' => 0, 'skipped' => 1, 'generation' => 'generation-same', 'photos' => [], 'feedback' => []],
            ['added' => 0, 'skipped' => 1, 'generation' => 'generation-same', 'photos' => [], 'feedback' => []],
        );
        $builder->shouldReceive('releaseLock')->twice();

        (new IndexVisualReference('photo', $photo->id))->handle($builder);
        (new IndexVisualReference('photo', $photo->id))->handle($builder);

        $this->assertSame('indexed', $photo->fresh()->index_status);
        $this->assertSame(1, $product->photos()->count());
    }

    public function test_job_marks_feedback_indexed_via_batch(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $product = Product::create(['sku' => 'SKU123']);
        $row = SearchFeedback::create([
            'user_id' => $admin->id, 'confirmed_product_id' => $product->id,
            'predicted_sku' => 'SKU123', 'confirmed_sku' => 'SKU123',
            'disk' => 'local', 'query_image_path' => 'verified-search/q.webp',
            'photo_hash' => str_repeat('a', 64), 'dhash' => str_repeat('0', 16),
            'training_status' => 'verified', 'reference_eligible' => true,
        ]);
        $builder = $this->fakeBuilder();
        $builder->shouldReceive('acquireLock')->once()->andReturn(true);
        $builder->shouldReceive('pruneStaleWorkspaces')->once()->andReturn(0);
        $builder->shouldReceive('appendPending')->once()->andReturnUsing(function () use ($row) {
            $row->update(['indexed_at' => now()]);

            return ['added' => 1, 'skipped' => 0, 'generation' => 'generation-fb', 'photos' => [], 'feedback' => [$row->id]];
        });
        $builder->shouldReceive('releaseLock')->once();

        (new IndexVisualReference('feedback', $row->id))->handle($builder);

        $this->assertNotNull($row->fresh()->indexed_at);
    }

    public function test_job_noop_when_nothing_pending(): void
    {
        $product = Product::create(['sku' => 'SKU123']);
        $photo = $product->photos()->create(['path' => 'products/a.jpg', 'disk' => 'public', 'index_status' => 'indexed']);
        $builder = $this->fakeBuilder();
        $builder->shouldReceive('acquireLock')->once()->andReturn(true);
        $builder->shouldReceive('pruneStaleWorkspaces')->once()->andReturn(0);
        $builder->shouldReceive('appendPending')->once()->andReturn(
            ['added' => 0, 'skipped' => 0, 'generation' => null, 'photos' => [], 'feedback' => []]
        );
        $builder->shouldReceive('releaseLock')->once();

        (new IndexVisualReference('photo', $photo->id))->handle($builder);

        $this->assertSame('indexed', $photo->fresh()->index_status);
    }

    public function test_failed_hook_marks_photo_failed(): void
    {
        $product = Product::create(['sku' => 'SKU123']);
        $photo = $product->photos()->create(['path' => 'products/a.jpg', 'disk' => 'public', 'index_status' => 'indexing']);

        (new IndexVisualReference('photo', $photo->id))->failed(new \RuntimeException('boom'));

        $this->assertSame('failed', $photo->fresh()->index_status);
    }

    public function test_lock_contention_defers_without_failing(): void
    {
        config(['queue.default' => 'database']);
        $product = Product::create(['sku' => 'SKU123']);
        $photo = $product->photos()->create(['path' => 'products/a.jpg', 'disk' => 'public', 'index_status' => 'pending']);
        IndexVisualReference::dispatch('photo', $photo->id);
        $this->assertSame(1, \Illuminate\Support\Facades\DB::table('jobs')->count());

        $lock = Cache::lock('visual-index-build', 86400);
        $this->assertTrue($lock->acquire());

        try {
            // Lock held: the worker must release the job back (delayed)
            // instead of failing it or touching the photo status.
            $this->artisan('queue:work', ['--once' => true, '--tries' => 1, '--sleep' => 0]);
            $this->assertSame('pending', $photo->fresh()->index_status);
            // Job was released for a later attempt, not deleted nor failed.
            $this->assertSame(1, \Illuminate\Support\Facades\DB::table('jobs')->count()
                + \Illuminate\Support\Facades\DB::table('failed_jobs')->count());
        } finally {
            $lock->release();
            \Illuminate\Support\Facades\DB::table('jobs')->delete();
        }
    }

    public function test_delete_indexed_marks_rebuild_required(): void
    {
        Storage::fake('public');
        $admin = User::factory()->create(['role' => 'admin']);
        $product = Product::create(['sku' => 'SKU123']);
        $photo = $product->photos()->create(['path' => 'products/a.jpg', 'disk' => 'public', 'index_status' => 'indexed']);

        $this->actingAs($admin)->delete(route('admin.photos.destroy', $photo))->assertRedirect();

        $this->assertSame('photo '.$photo->id.' deleted; full rebuild required', Cache::get('visual-index-rebuild-required'));
        $this->assertSame(0, $product->photos()->count());
    }

    public function test_delete_pending_needs_no_rebuild(): void
    {
        Storage::fake('public');
        Cache::forget('visual-index-rebuild-required');
        $admin = User::factory()->create(['role' => 'admin']);
        $product = Product::create(['sku' => 'SKU123']);
        $photo = $product->photos()->create(['path' => 'products/a.jpg', 'disk' => 'public', 'index_status' => 'pending']);

        $this->actingAs($admin)->delete(route('admin.photos.destroy', $photo))->assertRedirect();

        $this->assertNull(Cache::get('visual-index-rebuild-required'));
    }

    public function test_product_metadata_change_flags_indexed_photos(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $product = Product::create(['sku' => 'SKU123', 'description' => 'Obeng plus']);
        $photo = $product->photos()->create(['path' => 'products/a.jpg', 'disk' => 'public', 'index_status' => 'indexed']);

        $this->actingAs($admin)->put(route('admin.products.update', $product), [
            'sku' => 'SKU123', 'description' => 'Obeng minus',
        ])->assertRedirect();

        $this->assertSame('rebuild-required', $photo->fresh()->index_status);
        $this->assertNotNull(Cache::get('visual-index-rebuild-required'));
    }
}
