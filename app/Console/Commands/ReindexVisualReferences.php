<?php

namespace App\Console\Commands;

use App\Models\ProductPhoto;
use App\Services\VisualRepresentation;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class ReindexVisualReferences extends Command
{
    protected $signature = 'products:reindex-visual {--after-id=0} {--chunk=50} {--force : Recompute already indexed photos}';

    protected $description = 'Index pending visual references in bounded resumable batches';

    public function handle(VisualRepresentation $client): int
    {
        $lock = Cache::lock('visual-reference-reindex', 86400);
        if (! $lock->get()) {
            $this->error('Another indexing command is active.');

            return self::FAILURE;
        }
        $failed = $processed = $last = 0;
        try {
            $query = ProductPhoto::query()->where('id', '>', max(0, (int) $this->option('after-id')));
            if (! $this->option('force')) {
                $query->whereNotExists(function ($query): void {
                    $query->selectRaw('1')->from('visual_references')
                        ->whereColumn('photo_id', 'product_photos.id')
                        ->whereColumn('source_path', 'product_photos.path')
                        ->where('pipeline', config('visual_search.pipeline'));
                });
            }
            $through = ProductPhoto::max('id') ?? 0;
            $query->where('id', '<=', $through)->chunkById(max(1, min(200, (int) $this->option('chunk'))), function ($photos) use ($client, &$processed, &$failed, &$last): void {
                foreach ($photos as $photo) {
                    $last = $photo->id;
                    try {
                        $client->index($photo);
                        $processed++;
                    } catch (\Throwable $exception) {
                        $failed++;
                        $this->warn("Photo {$last}: ".$exception::class);
                    }
                }
                $this->info("Processed: {$processed}; failed: {$failed}; last ID: {$last}");
            });
        } finally {
            $lock->release();
        }
        $this->info("Resume: --after-id={$last}. Omit after-id to retry failures; completed rows are skipped.");

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
