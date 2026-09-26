<?php

namespace App\Jobs;

use App\Services\VisualIndexBuilder;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;

/**
 * Coalesced incremental indexing trigger.
 *
 * The kind/id is only a hint (ID, never image bytes): the handler drains
 * EVERY pending photo + unindexed eligible feedback into ONE new FAISS
 * generation via VisualIndexBuilder::appendPending(). Ten simultaneous
 * uploads therefore produce one generation instead of ten sequential
 * SigLIP/DINO loads.
 *
 * Idempotent: image_id dedup inside build_index skips already-present
 * references. Lock contention never fails permanently — the job is released
 * back with a delay so the reference joins a later generation instead.
 */
class IndexVisualReference implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 8;

    public function backoff(): array
    {
        return [60, 300, 900, 1800];
    }

    public function __construct(public readonly string $kind, public readonly int $id)
    {
        //
    }

    public function handle(VisualIndexBuilder $builder): void
    {
        if (! $builder->acquireLock()) {
            $this->release(120);

            return;
        }
        Cache::put('visual-index-building', true, 300);
        try {
            // Maintenance must never fail the main run (prune is fail-safe
            // by contract; this guard covers any future regression).
            try {
                $builder->pruneStaleWorkspaces();
            } catch (\Throwable $error) {
                report($error);
            }
            // appendPending owns all status transitions (indexing → indexed
            // / failed / rebuild-required) and is a no-op when nothing is
            // pending — including when the hint row itself is already
            // indexed, deleted, or ineligible.
            try {
                $result = $builder->appendPending();
                // Bounded drain: one batch is capped (limit 50) for CPU/RAM
                // safety, so a backlog beyond the limit needs a follow-up.
                // Exactly ONE continuation is scheduled, and only after a
                // productive batch (a published generation). Poison rows that
                // can never build (failed-forever) publish nothing, which
                // terminates the chain instead of looping. No in-worker loop,
                // no synchronous recursion: the follow-up is a queued job
                // that re-acquires the single-writer lock like any other.
                // Rebuild-required failures return via the catch below (no
                // continuation): the queued full rebuild drains leftovers.
                if (($result['generation'] ?? null) !== null && $builder->hasPendingReferences()) {
                    self::dispatch($this->kind, $this->id)->afterCommit();
                }
            } catch (\RuntimeException $error) {
                // Rebuild-required (signature drift, changed/deleted refs)
                // is already marked by the builder with a cache flag; it
                // must NOT fail the upload/feedback request nor retry
                // endlessly — only a full rebuild can heal it. Generic
                // failures rethrow for queue backoff retry.
                if ($builder->isRebuildRequired($error->getMessage())) {
                    report($error);

                    return;
                }
                throw $error;
            }
        } finally {
            $builder->releaseLock();
            Cache::forget('visual-index-building');
        }
    }

    public function failed(\Throwable $error): void
    {
        // Crash safety net: rows left in indexing by an interrupted batch
        // are retried as pending by the next appendPending() run. Reset the
        // hint row here so a single-row failure never sticks in indexing.
        if ($this->kind !== 'feedback') {
            \App\Models\ProductPhoto::whereKey($this->id)
                ->where('index_status', 'indexing')
                ->update(['index_status' => 'failed']);
        }
        // Feedback rows keep indexed_at null (pending) so a later run or a
        // full build still picks them up.
    }
}
