<?php

namespace App\Console\Commands;

use App\Models\ProductPhoto;
use App\Services\ProductImages;
use App\Services\VisualIndexLifecycle;
use Illuminate\Console\Command;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

class CompressProductImages extends Command
{
    protected $signature = 'products:compress-images {--dry-run} {--after-id=0} {--chunk=100} {--retry-preserved}';

    protected $description = 'Process catalog variants in bounded chunks; old files are retained for rollback';

    public function handle(): int
    {
        $chunk = filter_var($this->option('chunk'), FILTER_VALIDATE_INT);
        $after = filter_var($this->option('after-id'), FILTER_VALIDATE_INT);
        if (! $chunk || $chunk < 1 || $chunk > 500 || $after === false || $after < 0) {
            $this->error('Invalid chunk/after-id');

            return self::FAILURE;
        }
        $lock = Cache::lock('product-image-maintenance', 86400);
        if (! $lock->get()) {
            $this->error('Maintenance already running');

            return self::FAILURE;
        }
        $through = ProductPhoto::max('id') ?? 0;
        $processed = $skipped = $failed = 0;
        $last = $after;
        try {
            foreach (ProductPhoto::with('product')->where('id', '>', $after)->where('id', '<=', $through)->lazyById($chunk) as $photo) {
                $last = $photo->id;
                $tag = 'ID '.$photo->id.' / SKU '.($photo->product->sku ?? '?');
                if ($photo->optimization_status === 'optimized' || (! $this->option('retry-preserved') && str_starts_with($photo->optimization_status ?? '', 'preserved_'))) {
                    $skipped++;

                    continue;
                }
                // No source image in database (null/empty path): never call
                // the AI service, never fabricate data — skip, don't fail.
                $source = $photo->master_path ?: $photo->path;
                if (! is_string($source) || $source === '') {
                    $skipped++;
                    $this->line($tag.': SKIPPED — no source image');

                    continue;
                }
                if ($this->option('dry-run')) {
                    $this->line('Would process ID '.$photo->id);

                    continue;
                }
                $temporary = tempnam(sys_get_temp_dir(), 'lensku-');
                $stored = null;
                try {
                    $read = Storage::disk($photo->diskName())->readStream($source);
                    if (! is_resource($read)) {
                        throw new \RuntimeException('Missing source image');
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
                    // Readable but zero bytes: corrupt storage content, not a
                    // missing image. Fail loudly instead of sending an empty
                    // body the AI must reject with 422.
                    if (filesize($temporary) === 0) {
                        throw new \RuntimeException('Source image is empty');
                    }
                    $stored = app(ProductImages::class)->store(new UploadedFile($temporary, basename($source), null, null, true));
                    if ($stored['optimization_status'] !== 'optimized') {
                        $photo->update(['optimization_status' => $stored['optimization_status']]);
                        app(ProductImages::class)->discard($stored);
                        $stored = null;
                        $skipped++;

                        continue;
                    }
                    // Re-encoded bytes change source_file_hash: an already-served
                    // reference can only be replaced by a full rebuild, never
                    // by incremental append (which would fail Changed-image).
                    $wasServed = in_array($photo->index_status, ['indexed', 'indexing', 'rebuild-required'], true);
                    $updated = ProductPhoto::whereKey($photo->id)->where('updated_at', $photo->getRawOriginal('updated_at'))->where('path', $photo->path)
                        ->update([...$stored, 'index_status' => $wasServed ? 'rebuild-required' : 'pending']);
                    if ($wasServed && $updated) {
                        app(VisualIndexLifecycle::class)->markDirty("photo {$photo->id} re-encoded; full rebuild required");
                    }
                    if (! $updated) {
                        app(ProductImages::class)->discard($stored);
                        $skipped++;
                    } else {
                        $processed++;
                    }
                    $stored = null;
                } catch (\Throwable $error) {
                    if ($stored) {
                        app(ProductImages::class)->discard($stored);
                    }
                    $failed++;
                    $this->error($tag.': '.$error->getMessage());
                } finally {
                    if (is_file($temporary)) {
                        unlink($temporary);
                    }
                }
            }
            $this->info("Processed: $processed | Skipped: $skipped | Failed: $failed | Last ID: $last | Through ID: $through");
            $this->line('Resume with --after-id='.$last.'. Omit it to retry failed rows. Old files retained.');

            return $failed ? self::FAILURE : self::SUCCESS;
        } finally {
            $lock->release();
        }
    }
}
