<?php

namespace App\Console\Commands;

use App\Models\ProductPhoto;
use App\Models\SearchFeedback;
use App\Services\ProductAttributes;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class ExportVisualReferences extends Command
{
    protected $signature = 'products:export-visual {directory : New local dataset directory} {--include-verified : Include quality-checked confirmed search references}';

    protected $description = 'Stream reference masters and crop metadata to a private build dataset';

    public function handle(): int
    {
        $directory = $this->argument('directory');
        if (file_exists($directory) || ! mkdir($directory, 0700, true)) {
            $this->error('Use a new directory; existing datasets are never overwritten.');

            return self::FAILURE;
        }
        $count = 0;
        $this->writeFile($directory.DIRECTORY_SEPARATOR.'.exporting', 'incomplete');
        $throughPhoto = ProductPhoto::max('id') ?? 0;
        $throughFeedback = $this->option('include-verified') ? (SearchFeedback::max('id') ?? 0) : 0;
        foreach (ProductPhoto::with('product.attributes')->where('id', '<=', $throughPhoto)->lazyById(100) as $photo) {
            if (! preg_match('/^[a-zA-Z0-9_-]+$/D', $photo->product->sku)) {
                throw new \RuntimeException('SKU cannot be represented safely as a folder: '.$photo->id);
            }
            $folder = $directory.DIRECTORY_SEPARATOR.$photo->product->sku;
            if (! is_dir($folder)) {
                mkdir($folder, 0700);
            }
            $source = $photo->master_path ?: $photo->path;
            $extension = strtolower(pathinfo($source, PATHINFO_EXTENSION));
            if (! in_array($extension, ['jpg', 'jpeg', 'png', 'webp'], true)) {
                throw new \RuntimeException('Unsupported reference format');
            }
            $stream = Storage::disk($photo->diskName())->readStream($source);
            if (! is_resource($stream)) {
                throw new \RuntimeException('Missing photo '.$photo->id);
            }
            $target = fopen($folder.DIRECTORY_SEPARATOR.$photo->id.'.'.$extension, 'xb');
            try {
                if (stream_copy_to_stream($stream, $target) === false) {
                    throw new \RuntimeException('Export failed');
                }
            } finally {
                fclose($stream);
                fclose($target);
            }
            $this->writeFile($folder.DIRECTORY_SEPARATOR.$photo->id.'.json', json_encode([
                'product_id' => $photo->product_id, 'description' => $photo->product->description,
                'trusted_attributes' => ProductAttributes::trustedForExport($photo->product),
                'source_photo_hash' => $photo->photo_hash,
                'crop' => $photo->crop, 'selection_source' => $photo->selection_source,
                // Pre-provenance catalog photos predate the source column.
                'selection_verified' => $photo->selection_verified, 'source' => $photo->source ?: 'catalog',
                'capture_group' => 'reference-'.$photo->id,
            ], JSON_THROW_ON_ERROR));
            $count++;
        }
        if ($this->option('include-verified')) {
            foreach (SearchFeedback::with('confirmedProduct.attributes')->where('id', '<=', $throughFeedback)
                ->whereHas('confirmedProduct', fn ($query) => $query->whereColumn('products.sku', 'search_feedback.confirmed_sku'))
                ->where('reference_eligible', true)->where('training_status', 'verified')->lazyById(100) as $row) {
                if (! preg_match('/^[a-zA-Z0-9_-]+$/D', $row->confirmed_sku)) {
                    throw new \RuntimeException('Unsafe SKU');
                }
                $folder = $directory.DIRECTORY_SEPARATOR.$row->confirmed_sku;
                if (! is_dir($folder)) {
                    mkdir($folder, 0700);
                }
                $base = $folder.DIRECTORY_SEPARATOR.'verified-'.$row->id;
                $source = Storage::disk($row->disk)->readStream($row->query_image_path);
                if (! is_resource($source)) {
                    throw new \RuntimeException('Missing verified image');
                }
                $target = fopen($base.'.webp', 'xb');
                try {
                    if (stream_copy_to_stream($source, $target) === false) {
                        throw new \RuntimeException('Export failed');
                    }
                } finally {
                    fclose($source);
                    fclose($target);
                }
                $this->writeFile($base.'.json', json_encode([
                    'product_id' => $row->confirmed_product_id, 'crop' => $row->crop,
                    'selection_source' => $row->selection_source,
                    'source' => 'confirmed_search', 'capture_group' => $row->evidence['capture_group'] ?? 'unknown-session',
                    'selection_verified' => true, 'description' => $row->confirmedProduct->description,
                    'trusted_attributes' => ProductAttributes::trustedForExport($row->confirmedProduct),
                    'source_photo_hash' => $row->photo_hash,
                ], JSON_THROW_ON_ERROR));
                $count++;
            }
        }
        unlink($directory.DIRECTORY_SEPARATOR.'.exporting');
        $this->info('Exported '.$count.' references. Build must succeed before publishing this dataset.');

        return self::SUCCESS;
    }

    private function writeFile(string $path, string $content): void
    {
        if (file_put_contents($path, $content) !== strlen($content)) {
            throw new \RuntimeException('Incomplete export file: '.$path);
        }
    }
}
