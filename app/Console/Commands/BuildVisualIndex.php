<?php

namespace App\Console\Commands;

use App\Services\StoragePaths;
use App\Services\VisualIndexBuilder;
use App\Services\VisualIndexLifecycle;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

class BuildVisualIndex extends Command
{
    protected $signature = 'search:build-index {--include-verified : Include eligible human-confirmed references}';

    protected $description = 'Export a consistent reference dataset and publish a complete FAISS generation';

    public function handle(VisualIndexBuilder $builder, VisualIndexLifecycle $lifecycle): int
    {
        $lock = Cache::lock('visual-index-build', 86400);
        if (! $lock->get()) {
            $this->error('Another build is running.');

            return self::FAILURE;
        }
        $dataset = Storage::disk('local')->path(app(StoragePaths::class)->indexBuild());
        try {
            // Capture the dirty revision AND the snapshot boundary BEFORE the
            // authoritative snapshot starts: a mutation landing mid-build
            // must never be considered covered by this generation.
            $buildRevision = $lifecycle->dirtyRevision();
            $snapshotAt = now();
            try {
                $builder->exportDataset($dataset, (bool) $this->option('include-verified'));
            } catch (\RuntimeException $error) {
                $this->error($error->getMessage().' Dataset retained: '.$dataset);

                return self::FAILURE;
            }
            // Same authoritative pipeline as the automatic queue rebuild.
            try {
                $builder->rebuildFull($dataset, fn ($type, $buffer) => $this->output->write($buffer));
            } catch (\RuntimeException $error) {
                $this->error('Build failed; serving generation was not replaced. Dataset retained: '.$dataset);

                return self::FAILURE;
            }
            $this->info('Generation published. AI worker hot-reloads it automatically; no restart needed.');
            $this->line('Private export: '.$dataset);
            // Timestamp for the admin "awaiting index" nudge; failures above
            // return before this line, so it only marks successful builds.
            Cache::forever('visual-index-built-at', now()->toIso8601String());
            // Reconcile only rows covered by this snapshot; mid-build
            // mutations keep their pending/rebuild-required status.
            // Feedback is healed only when this rebuild actually included
            // verified references; otherwise pending confirmations stay
            // pending for the next auto-index run.
            $builder->markAllIndexed((bool) $this->option('include-verified'), $snapshotAt);
            if (! $lifecycle->clearDirty($buildRevision)) {
                // A mutation landed mid-build: ensure a follow-up rebuild
                // (no-op when one is already queued).
                $lifecycle->ensureQueued();
                $this->line('New changes arrived during the build; a follow-up rebuild was queued.');
            }

            return self::SUCCESS;
        } finally {
            $lock->release();
        }
    }
}
