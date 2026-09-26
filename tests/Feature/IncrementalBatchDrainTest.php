<?php

namespace Tests\Feature;

use App\Jobs\IndexVisualReference;
use App\Models\Product;
use App\Services\VisualIndexBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Bounded batch drain: appendPending() processes at most 50 photos per run
 * for CPU/RAM safety, so a backlog beyond the limit must continue via
 * exactly one queued follow-up per productive batch — never a storm, never
 * an in-worker loop, and poison rows terminate the chain.
 *
 * The expensive Python boundary is stubbed; no model downloads.
 */
class IncrementalBatchDrainTest extends TestCase
{
    use RefreshDatabase;

    /** @return list<int> photo ids */
    private function seedPending(Product $product, int $count, string $prefix = 'products/drain'): array
    {
        Storage::fake('public');
        $ids = [];
        for ($i = 0; $i < $count; $i++) {
            $name = "{$prefix}-{$i}.jpg";
            Storage::disk('public')->put($name, 'fake-image-bytes');
            $ids[] = $product->photos()->create(['path' => $name, 'disk' => 'public', 'index_status' => 'pending'])->id;
        }

        return $ids;
    }

    private function successfulBuilder(): VisualIndexBuilder
    {
        $builder = \Mockery::mock(VisualIndexBuilder::class)->makePartial()
            ->shouldAllowMockingProtectedMethods();
        $builder->shouldReceive('pruneStaleWorkspaces')->andReturn(0);
        // Instant "Python build": every exported reference publishes.
        $builder->shouldReceive('runBuilder')->andReturn(['added' => 50, 'skipped' => 0, 'generation' => 'generation-drain']);

        return $builder;
    }

    private function runJob(int $hintId, VisualIndexBuilder $builder): void
    {
        (new IndexVisualReference('photo', $hintId))->handle($builder);
    }

    public function test_overflow_schedules_exactly_one_continuation_then_stops(): void
    {
        Queue::fake();
        $product = Product::create(['sku' => 'DRAIN-120']);
        $ids = $this->seedPending($product, 55);

        // Batch #1: 50 indexed, 5 remain → exactly one follow-up queued.
        $this->runJob($ids[0], $this->successfulBuilder());
        $this->assertSame('indexed', \App\Models\ProductPhoto::whereKey(array_slice($ids, 0, 50))->pluck('index_status')->unique()->sole());
        $this->assertSame(5, $product->photos()->where('index_status', 'pending')->count());
        Queue::assertPushed(IndexVisualReference::class, 1);

        // Batch #2 (the follow-up): drains the final 5, queues nothing more.
        $this->runJob($ids[50], $this->successfulBuilder());
        $this->assertSame(0, $product->photos()->whereIn('index_status', ['pending', 'failed', 'indexing'])->count());
        $this->assertSame(55, $product->photos()->where('index_status', 'indexed')->count());
        Queue::assertPushed(IndexVisualReference::class, 1);
    }

    public function test_exact_limit_needs_no_follow_up(): void
    {
        Queue::fake();
        $product = Product::create(['sku' => 'DRAIN-50']);
        $ids = $this->seedPending($product, 50);

        $this->runJob($ids[0], $this->successfulBuilder());

        $this->assertSame(50, $product->photos()->where('index_status', 'indexed')->count());
        Queue::assertNotPushed(IndexVisualReference::class);
    }

    public function test_one_over_limit_schedules_one_follow_up(): void
    {
        Queue::fake();
        $product = Product::create(['sku' => 'DRAIN-51']);
        $ids = $this->seedPending($product, 51);

        $this->runJob($ids[0], $this->successfulBuilder());
        Queue::assertPushed(IndexVisualReference::class, 1);

        $this->runJob($ids[50], $this->successfulBuilder());
        $this->assertSame(51, $product->photos()->where('index_status', 'indexed')->count());
        Queue::assertPushed(IndexVisualReference::class, 1);
    }

    public function test_poison_row_terminates_the_chain_as_failed(): void
    {
        Queue::fake();
        $product = Product::create(['sku' => 'DRAIN-POISON']);
        $ids = $this->seedPending($product, 50, 'products/good');
        // One corrupt upload beyond the first batch: no file behind it.
        $poison = $product->photos()->create(['path' => 'products/gone.jpg', 'disk' => 'public', 'index_status' => 'pending']);

        // Batch #1 publishes 50 → follow-up for the leftover.
        $this->runJob($ids[0], $this->successfulBuilder());
        Queue::assertPushed(IndexVisualReference::class, 1);

        // Batch #2: poison export fails → failed, nothing published → stop.
        $this->runJob($poison->id, $this->successfulBuilder());
        $this->assertSame('failed', $poison->fresh()->index_status);
        $this->assertSame(50, $product->photos()->where('index_status', 'indexed')->count());
        Queue::assertPushed(IndexVisualReference::class, 1);
    }
}
