<?php

namespace App\Console\Commands;

use App\Models\SearchFeedback;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class ExportTrainingSamples extends Command
{
    public const DATASET_SCHEMA = 'training-jsonl-v1';

    protected $signature = 'search:export-training {output : New private JSONL file}';

    protected $description = 'Export verified labels and hard negatives for offline training, never train a model';

    public function handle(): int
    {
        $file = fopen($this->argument('output'), 'x');
        if (! $file) {
            return self::FAILURE;
        }
        // Image bytes are never duplicated: the anchor references the stored
        // verified query image by disk/path. Rows are marked exported only
        // after their line is written, so a failed export never claims rows
        // that are missing from the file.
        $exported = [];
        try {
            $throughId = SearchFeedback::max('id') ?? 0;
            foreach (SearchFeedback::where('id', '<=', $throughId)->where('training_status', 'verified')
                ->whereHas('confirmedProduct', fn ($query) => $query->whereColumn('products.sku', 'search_feedback.confirmed_sku'))
                ->where('reference_eligible', true)->lazyById(100) as $row) {
                if (! $row->user_id || ! $row->confirmed_product_id
                    || ! Storage::disk($row->disk)->exists($row->query_image_path)) {
                    $this->warn('Skipped invalid or missing verified sample '.$row->id);

                    continue;
                }
                $line = json_encode([
                    'dataset_schema' => self::DATASET_SCHEMA,
                    'anchor' => ['disk' => $row->disk, 'path' => $row->query_image_path, 'crop' => $row->crop],
                    'positive_sku' => $row->confirmed_sku,
                    'hard_negative_sku' => $row->predicted_sku !== $row->confirmed_sku ? $row->predicted_sku : null,
                    'photo_hash' => $row->photo_hash, 'capture_group' => $row->evidence['capture_group'] ?? 'unknown-session',
                    'source' => 'confirmed_search', 'selection_source' => $row->selection_source,
                    'verified_by' => $row->user_id, 'verified_at' => $row->created_at->toIso8601String(),
                    'exported_at' => now()->toIso8601String(),
                    'pipeline_signature_hash' => $row->evidence['pipeline_signature_hash'] ?? null,
                    'preprocessing' => $row->evidence['query'] ?? [],
                ], JSON_THROW_ON_ERROR).PHP_EOL;
                if (fwrite($file, $line) !== strlen($line)) {
                    throw new \RuntimeException('Incomplete training export; rows remain unmarked.');
                }
                $exported[] = $row->id;
            }
            if (! fflush($file)) {
                throw new \RuntimeException('Failed to flush training export; rows remain unmarked.');
            }
        } finally {
            fclose($file);
        }
        foreach (array_chunk($exported, 500) as $chunk) {
            SearchFeedback::whereIn('id', $chunk)->update(['training_exported_at' => now()]);
        }
        $this->info('Exported '.count($exported).' verified samples.');

        return self::SUCCESS;
    }
}
