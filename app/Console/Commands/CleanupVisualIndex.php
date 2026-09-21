<?php

namespace App\Console\Commands;

use App\Services\IndexRetention;
use App\Services\VisualIndexBuilder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class CleanupVisualIndex extends Command
{
    protected $signature = 'search:cleanup-index {--dry-run : Show what would be removed without deleting anything} {--force : Delete without asking for confirmation}';

    protected $description = 'Inspect and prune superseded FAISS generations, stale build dirs, and old private index workspaces';

    public function handle(VisualIndexBuilder $builder): int
    {
        $dryRun = (bool) $this->option('dry-run');
        // Destructive cleanup holds the SAME single-writer lock as indexing
        // for the whole plan + delete operation, so a concurrent incremental
        // append or full rebuild can never interleave with our deletions.
        // Dry-run modifies nothing and needs no lock.
        $lock = Cache::lock('visual-index-build', 86400);
        if (! $dryRun && ! $lock->get()) {
            $this->error('Another build or cleanup is running; aborting without deleting anything.');

            return self::FAILURE;
        }
        try {
            return $this->runCleanup($builder, $dryRun);
        } finally {
            if (! $dryRun) {
                try {
                    $lock->release();
                } catch (\Throwable) {
                }
            }
        }
    }

    private function runCleanup(VisualIndexBuilder $builder, bool $dryRun): int
    {
        $root = (string) config('retrieval.index_path');
        $plan = IndexRetention::plan($root,
            (int) config('retrieval.index_generations_keep', 3),
            (int) config('retrieval.index_generation_grace_seconds', 3600),
            (int) config('retrieval.index_build_stale_hours', 24));
        $workspaces = $builder->staleWorkspaceCandidates();
        $reclaimable = array_sum(array_column($plan['remove'], 'size_mb'))
            + array_sum(array_column($plan['builds'], 'size_mb'))
            + array_sum(array_column($workspaces, 'size_mb'));

        $this->line('Index root: '.$root);
        $this->line('Current generation: '.($plan['current'] ?? '(missing or corrupt — nothing will be removed)'));
        $this->newLine();
        $this->line('Keep:');
        foreach ($plan['keep'] as $row) {
            $tag = $row['name'] === $plan['current'] ? '  (CURRENT)' : '';
            $this->line("  {$row['name']}  {$row['size_mb']} MB{$tag}");
        }
        if ($plan['keep'] === []) {
            $this->line('  (none)');
        }
        $this->newLine();
        $this->line('Remove:');
        foreach ($plan['remove'] as $row) {
            $this->line("  {$row['name']}  {$row['size_mb']} MB");
        }
        if ($plan['remove'] === []) {
            $this->line('  (none)');
        }
        $this->newLine();
        $this->line('Stale build workspaces:'.($plan['builds_skipped'] ? ' (skipped: build.lock present)' : ''));
        foreach ($plan['builds'] as $row) {
            $this->line("  {$row['name']}  {$row['size_mb']} MB");
        }
        if ($plan['builds'] === [] && ! $plan['builds_skipped']) {
            $this->line('  (none)');
        }
        $this->newLine();
        $this->line('Private exports (index-builds, older than '.(int) config('retrieval.index_export_retention_days', 3).' days):');
        foreach ($workspaces as $row) {
            $this->line("  {$row['name']}  {$row['size_mb']} MB");
        }
        if ($workspaces === []) {
            $this->line('  (none)');
        }
        $this->newLine();
        $this->line('Estimated reclaimable: '.round($reclaimable, 1).' MB');

        if ($dryRun) {
            $this->info('Dry-run: nothing was modified.');

            return self::SUCCESS;
        }
        if ($plan['remove'] === [] && $plan['builds'] === [] && $workspaces === []) {
            $this->info('Nothing to clean.');

            return self::SUCCESS;
        }
        if (! $this->option('force') && ! $this->confirm('Delete the items listed above?')) {
            $this->line('Aborted; nothing was modified.');

            return self::SUCCESS;
        }
        // Re-plan under the lock and intersect: never execute a stale plan
        // if CURRENT moved (or grace elapsed into eligibility) meanwhile.
        // removePlanned() additionally re-checks CURRENT, naming, symlinks,
        // and location for every single target.
        $fresh = IndexRetention::plan($root,
            (int) config('retrieval.index_generations_keep', 3),
            (int) config('retrieval.index_generation_grace_seconds', 3600),
            (int) config('retrieval.index_build_stale_hours', 24));
        $allowed = [];
        foreach (array_merge($fresh['remove'], $fresh['builds']) as $row) {
            $allowed[$row['name']] = true;
        }
        $failures = 0;
        foreach (array_merge($plan['remove'], $plan['builds']) as $row) {
            if (! isset($allowed[$row['name']])) {
                $this->warn("Skipping {$row['name']}; no longer eligible.");

                continue;
            }
            if (! IndexRetention::removePlanned($root, $row['name'])) {
                $this->warn("Could not remove {$row['name']}; left in place.");
                $failures++;
            }
        }
        foreach ($workspaces as $row) {
            if (! $builder->removeWorkspace($row['path'])) {
                $this->warn("Could not remove workspace {$row['name']}; left in place.");
                $failures++;
            }
        }
        if ($failures > 0) {
            $this->warn("Cleanup finished with {$failures} item(s) left in place; serving and indexing are unaffected.");

            return self::FAILURE;
        }
        $this->info('Cleanup finished.');

        return self::SUCCESS;
    }
}
