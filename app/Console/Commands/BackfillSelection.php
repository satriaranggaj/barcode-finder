<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Models\ProductPhoto;
use App\Services\CropCoordinates;
use App\Services\RetrievalClient;
use Illuminate\Console\Command;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class BackfillSelection extends Command
{
    protected $signature = 'products:backfill-images {--dry-run : Report without changing files, database or index} {--product= : Only process one product SKU} {--force : Reprocess photos that already have a crop}';

    protected $description = 'Backfill auto object selection for existing product photos; pairs with products:compress-images and search:build-index';

    public function handle(): int
    {
        $sku = $this->option('product');
        $product = null;
        if (is_string($sku) && $sku !== '') {
            $product = Product::where('sku', $sku)->first();
            if (! $product) {
                $this->error("Unknown product SKU: {$sku}");

                return self::FAILURE;
            }
        }
        $lock = Cache::lock('product-image-maintenance', 86400);
        if (! $lock->get()) {
            $this->error('Maintenance already running');

            return self::FAILURE;
        }
        $query = ProductPhoto::with('product')->orderBy('id');
        if ($product) {
            $query->where('product_id', $product->id);
        }
        $total = (clone $query)->count();
        $processed = $skipped = $failed = $auto = $fallback = 0;
        $position = 0;
        try {
            foreach ((clone $query)->lazyById(100) as $photo) {
                $position++;
                $label = "[{$position}/{$total}] {$photo->product->sku}";
                if ($photo->crop !== null && ! $this->option('force')) {
                    $skipped++;
                    $this->line("{$label} - SKIP");

                    continue;
                }
                if ($this->option('dry-run')) {
                    [$status] = $this->propose($photo);
                    $status === 'OK' ? $auto++ : $fallback++;
                    $this->line("{$label} - {$status} (dry-run)");

                    continue;
                }
                [$status, $crop] = $this->propose($photo);
                if ($status === 'FAILED') {
                    $failed++;
                    $this->error("{$label} - FAILED");

                    continue;
                }
                if ($status !== 'OK' || $crop === null) {
                    $fallback++;
                    $this->line("{$label} - SELECTION FALLBACK");

                    continue;
                }
                // Optimistic concurrency: never clobber an admin edit that
                // landed while the AI call was in flight. crop updates bump
                // updated_at, so a mismatch means someone else wrote first.
                // A new auto crop changes the embedded vector: already-served
                // references wait for a full rebuild instead of poisoning an
                // incremental batch with a Changed-image failure.
                $wasServed = in_array($photo->index_status, ['indexed', 'indexing', 'rebuild-required'], true);
                $updated = ProductPhoto::whereKey($photo->id)
                    ->where('updated_at', $photo->getRawOriginal('updated_at'))
                    ->update(['crop' => $crop, 'selection_source' => 'auto',
                        'selection_verified' => false, 'index_status' => $wasServed ? 'rebuild-required' : 'pending']);
                if ($wasServed && $updated) {
                    Cache::forever('visual-index-rebuild-required', "photo {$photo->id} selection changed; full rebuild required");
                }
                if (! $updated) {
                    $skipped++;
                    $this->line("{$label} - SKIP (changed during backfill)");

                    continue;
                }
                $processed++;
                $auto++;
                $this->line("{$label} - OK");
            }
            if ($this->option('dry-run')) {
                $processed = $auto;
            }
            $this->info("Total: {$total} | Berhasil: {$processed} | Gagal: {$failed} | Skip: {$skipped} | Auto-selection berhasil: {$auto} | Selection fallback: {$fallback}");
            if (! $this->option('dry-run')) {
                $this->line('Reference dibuat oleh: php artisan search:build-index --include-verified (panduan: docs/backfill-image-selection.md).');
            }

            return $failed ? self::FAILURE : self::SUCCESS;
        } finally {
            $lock->release();
        }
    }

    /**
     * @return array{string, ?array} status (OK, SELECTION FALLBACK, FAILED) and validated crop.
     */
    private function propose(ProductPhoto $photo): array
    {
        $temporary = tempnam(sys_get_temp_dir(), 'lensku-backfill-');
        try {
            // Master preferred, existing/original path as fallback; works on
            // any configured disk (local, S3-compatible) via the abstraction.
            $source = $photo->master_path ?: $photo->path;
            $read = Storage::disk($photo->diskName())->readStream($source);
            if (! is_resource($read)) {
                $this->error("Photo {$photo->id}: missing source image");

                return ['FAILED', null];
            }
            $write = fopen($temporary, 'wb');
            try {
                if (stream_copy_to_stream($read, $write) === false) {
                    throw new \RuntimeException('Copy failed');
                }
            } finally {
                fclose($read);
                fclose($write);
            }
            $data = app(RetrievalClient::class)->send(
                'select', new UploadedFile($temporary, basename($source), null, null, true));
            $box = $data['candidates'][0]['box'] ?? $data['boxes'][0] ?? null;
            if (! is_array($box)) {
                return ['SELECTION FALLBACK', null];
            }
            try {
                $crop = CropCoordinates::validate($box);
            } catch (ValidationException $error) {
                $this->error("Photo {$photo->id}: invalid AI box");

                return ['FAILED', null];
            }
            if ($crop === null) {
                return ['SELECTION FALLBACK', null];
            }

            return ['OK', $crop];
        } catch (\Throwable $error) {
            $this->error("Photo {$photo->id}: {$error->getMessage()}");

            return ['FAILED', null];
        } finally {
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }
    }
}
