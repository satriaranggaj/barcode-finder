<?php

namespace App\Services;

use App\Jobs\RebuildVisualIndex;
use App\Models\ProductPhoto;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

/**
 * Central lifecycle for visual index invalidation.
 *
 * Every mutation path that changes already-served FAISS references (photo
 * delete, product metadata edit, crop/selection edit, re-encode, catalog
 * import) funnels through here so future writers cannot silently leave the
 * serving generation stale. New photos are NOT handled here — they take the
 * incremental path via IndexVisualReference.
 *
 * Dirty tracking uses a monotonic revision: mutations bump it, a successful
 * rebuild captures R and clears only when the revision still equals R, so a
 * mutation landing mid-build schedules a follow-up instead of being lost.
 * The legacy `visual-index-rebuild-required` reason key is still maintained
 * for the admin banner and existing tooling.
 */
final class VisualIndexLifecycle
{
    /** Statuses whose references may already live in the serving generation. */
    public const SERVED_STATUSES = ['indexed', 'indexing', 'rebuild-required'];

    public const REVISION_KEY = 'visual-index-dirty-revision';

    public const REASON_KEY = 'visual-index-dirty-reason';

    public const LEGACY_REASON_KEY = 'visual-index-rebuild-required';

    public const FAILURE_KEY = 'visual-index-rebuild-failure';

    public const LOCK_KEY = 'visual-index-dirty';

    /**
     * Set while a rebuild job is queued (or running) for the current dirty
     * state, so N simultaneous mutations schedule exactly ONE job. Cleared
     * when a rebuild starts and after a fully clean reconciliation; a stale
     * flag (e.g. manual queue surgery) is harmless because the dirty banner
     * remains and the manual command stays available.
     */
    public const QUEUED_KEY = 'visual-index-rebuild-queued';

    /**
     * Mark served photos as needing a full rebuild and schedule it.
     * Photos never served are left untouched (no rebuild needed for them).
     *
     * @param  list<int>  $photoIds
     * @return int number of photos flipped to rebuild-required
     */
    public function invalidateServedPhotos(array $photoIds, string $reason): int
    {
        if ($photoIds === [] || ! Schema::hasTable('product_photos')) {
            return 0;
        }
        $affected = ProductPhoto::whereKey($photoIds)
            ->whereIn('index_status', self::SERVED_STATUSES)
            ->update(['index_status' => 'rebuild-required']);
        if ($affected > 0) {
            $this->markDirty($reason);
        }

        return $affected;
    }

    /**
     * Bump the dirty revision and schedule one coalesced rebuild.
     * Simultaneous mutations collapse into a single queued job (queued flag)
     * and runtime dirty checks coalesce redeliveries into no-ops.
     *
     * @return int the new dirty revision
     */
    public function markDirty(string $reason): int
    {
        $lock = Cache::lock(self::LOCK_KEY, 30);
        $revision = $lock->block(5, function () use ($reason) {
            $revision = ((int) Cache::get(self::REVISION_KEY, 0)) + 1;
            Cache::forever(self::REVISION_KEY, $revision);
            Cache::forever(self::REASON_KEY, $reason);
            Cache::forever(self::LEGACY_REASON_KEY, $reason);

            return $revision;
        });
        // Dispatched OUTSIDE the lock on purpose: with a sync queue driver
        // the job runs inline, and holding this lock across dispatch would
        // deadlock against the job's own clearDirty() call.
        $this->ensureQueued();

        return $revision;
    }

    /**
     * Ensure one rebuild job is queued (no-op when one already is). Used by
     * both mutations and follow-up scheduling so redundant dispatches
     * collapse instead of piling up. Check-then-set races only ever produce
     * a duplicate job, which safely no-ops at runtime via the revision check.
     */
    public function ensureQueued(): void
    {
        if (! Cache::has(self::QUEUED_KEY)) {
            Cache::forever(self::QUEUED_KEY, true);
            RebuildVisualIndex::dispatch()->afterCommit();
        }
    }

    /**
     * Called when a rebuild run starts: a queued job now owns the dirty
     * state, so later mutations may schedule the next one.
     */
    public function claimQueued(): void
    {
        Cache::forget(self::QUEUED_KEY);
    }

    public function dirtyRevision(): int
    {
        return (int) Cache::get(self::REVISION_KEY, 0);
    }

    public function isDirty(): bool
    {
        return $this->dirtyRevision() > 0;
    }

    public function dirtyReason(): ?string
    {
        $reason = Cache::get(self::REASON_KEY);
        if (is_string($reason) && $reason !== '') {
            return $reason;
        }
        $legacy = Cache::get(self::LEGACY_REASON_KEY);

        return is_string($legacy) && $legacy !== '' ? $legacy : null;
    }

    /**
     * Clear dirty state only when no newer mutation landed meanwhile.
     * Returns false when a follow-up rebuild is required instead.
     */
    public function clearDirty(int $revision): bool
    {
        $lock = Cache::lock(self::LOCK_KEY, 30);

        return $lock->block(5, function () use ($revision) {
            if ((int) Cache::get(self::REVISION_KEY, 0) !== $revision) {
                return false;
            }
            Cache::forget(self::REVISION_KEY);
            Cache::forget(self::REASON_KEY);
            Cache::forget(self::LEGACY_REASON_KEY);
            Cache::forget(self::FAILURE_KEY);
            Cache::forget(self::QUEUED_KEY);

            return true;
        });
    }

    /**
     * Store a concise, UI-safe failure note. Never a traceback: the full
     * exception is reported to logs by the caller before invoking this.
     */
    public function failConcise(string $message): void
    {
        $oneLine = trim(preg_replace('/\s+/', ' ', $message) ?? '');
        if (mb_strlen($oneLine) > 200) {
            $oneLine = mb_substr($oneLine, -200);
        }
        Cache::forever(self::FAILURE_KEY, $oneLine === '' ? 'Automatic rebuild failed.' : $oneLine);
    }

    public function failure(): ?string
    {
        $failure = Cache::get(self::FAILURE_KEY);

        return is_string($failure) && $failure !== '' ? $failure : null;
    }

    public function clearFailure(): void
    {
        Cache::forget(self::FAILURE_KEY);
    }
}
