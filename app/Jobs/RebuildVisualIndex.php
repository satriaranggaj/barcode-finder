<?php

namespace App\Jobs;

use App\Services\StoragePaths;
use App\Services\VisualIndexBuilder;
use App\Services\VisualIndexLifecycle;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

/**
 * Automatic FULL visual index rebuild through the queue.
 *
 * Triggered (coalesced) whenever already-served FAISS references change in
 * ways HNSW cannot absorb incrementally: photo deletes, product metadata
 * edits, crop/selection edits, re-encodes, catalog imports. Uses the exact
 * same authoritative pipeline as `search:build-index` (full snapshot export
 * + `build_index.py --rebuild` + verify + atomic CURRENT + markAllIndexed),
 * so serving is never mutated in place and FastAPI hot-reloads the result.
 *
 * Coalescing is two-layered: the lifecycle queued-flag collapses
 * simultaneous mutations into a single dispatch, and the dirty-revision
 * check below turns redundant runs (including queue redeliveries after the
 * retry_after window) into successful no-ops. No uniqueness interface is
 * used on purpose: a uniqueness lock held across a long rebuild could drop
 * a mutation arriving mid-run, while the revision protocol never loses one.
 */
class RebuildVisualIndex implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function backoff(): array
    {
        return [600, 1800, 3600];
    }

    public function handle(VisualIndexBuilder $builder, VisualIndexLifecycle $lifecycle): void
    {
        if (! $builder->acquireLock()) {
            $this->release(300);

            return;
        }
        try {
            // This run now owns the dirty state; later mutations may queue
            // the next rebuild.
            $lifecycle->claimQueued();
            // Already healed (or never dirty): coalesced away, succeed quiet.
            if (! $lifecycle->isDirty()) {
                return;
            }
            $revision = $lifecycle->dirtyRevision();
            Cache::put('visual-index-building', true, 7200);
            try {
                try {
                    $builder->pruneStaleWorkspaces();
                } catch (\Throwable $error) {
                    report($error);
                }
                $dataset = Storage::disk('local')->path(app(StoragePaths::class)->indexBuild());
                try {
                    $builder->exportDataset($dataset, true);
                    $builder->rebuildFull($dataset);
                    $builder->markAllIndexed(true);
                    if ($lifecycle->clearDirty($revision)) {
                        $lifecycle->clearFailure();
                    } else {
                        // A mutation landed mid-build: its revision survives;
                        // ensure a follow-up rebuild reconciles it (no-op
                        // when one is already queued).
                        $lifecycle->ensureQueued();
                    }
                    // Private export retained for review/rollback, same as
                    // the manual command (never auto-deleted here).
                } catch (\Throwable $error) {
                    // Concise UI-safe note only; the full exception/traceback
                    // is reported to logs. Dirty state stays so CURRENT keeps
                    // serving and a retry or the manual command recovers.
                    $lifecycle->failConcise($error->getMessage());
                    throw $error;
                }
            } finally {
                Cache::forget('visual-index-building');
            }
        } finally {
            $builder->releaseLock();
        }
    }

    public function failed(\Throwable $error): void
    {
        // Dirty state is intentionally retained: CURRENT still serves, the
        // red banner stays up, and a retry or the manual command recovers.
        report($error);
    }
}
