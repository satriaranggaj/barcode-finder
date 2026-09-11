<?php

namespace App\Console\Commands;

use App\Services\ProductImageOptimizer;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

#[Signature('products:compress-images
    {--dry-run : Encode and validate temporary results without changing stored files or database}
    {--limit= : Maximum number of pending photos to attempt}
    {--quality= : WebP quality (1-100; defaults to configuration)}
    {--chunk=100 : Number of lightweight database rows per batch (1-1000)}
    {--after-id=0 : Resume after a previously reported photo ID}
    {--sleep=100 : Pause in milliseconds per photo (0-60000)}
    {--keep-originals : Retain original files after successful conversion}')]
#[Description('Optimize product photo storage in bounded batches without recalculating embeddings')]
class CompressProductImages extends Command
{
    public function handle(ProductImageOptimizer $optimizer): int
    {
        try {
            $optimizer->assertAvailable();
            $quality = $this->integerOption('quality', config('product_images.quality'), 1, 100);
            $limit = $this->integerOption('limit', PHP_INT_MAX, 1, PHP_INT_MAX);
            $chunk = $this->integerOption('chunk', 100, 1, 1000);
            $afterId = $this->integerOption('after-id', 0, 0, PHP_INT_MAX);
            $pause = $this->integerOption('sleep', 100, 0, 60000);
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $lock = fopen(storage_path('framework/products-compress-images.lock'), 'c');
        if ($lock === false || ! flock($lock, LOCK_EX | LOCK_NB)) {
            if (is_resource($lock)) {
                fclose($lock);
            }
            $this->error('Command kompresi lain sedang berjalan atau lock tidak dapat dibuka.');

            return self::FAILURE;
        }

        $attempted = $processed = $skipped = $failed = $before = $after = $reclaimed = 0;
        $dryRun = (bool) $this->option('dry-run');
        $started = microtime(true);
        try {
            DB::connection()->disableQueryLog();
            $maxId = (int) DB::table('product_photos')->max('id');
            $this->info(($dryRun ? 'DRY RUN' : 'APPLY')." | quality={$quality} | chunk={$chunk} | through-id={$maxId}");
            while ($attempted < $limit) {
                $photos = DB::table('product_photos')
                    ->select(['id', 'product_id', 'path'])
                    ->whereNull('storage_optimized_at')
                    ->where('id', '>', $afterId)->where('id', '<=', $maxId)
                    ->orderBy('id')->limit(min($chunk, $limit - $attempted))->get();
                if ($photos->isEmpty()) {
                    break;
                }
                foreach ($photos as $photo) {
                    $afterId = (int) $photo->id;
                    $attempted++;
                    try {
                        $result = $this->compress($photo, $optimizer, $quality, $dryRun);
                        $before += $result['before'];
                        $after += $result['after'];
                        $reclaimed += $result['reclaimed'];
                        $result['changed'] ? $processed++ : $skipped++;
                        $this->line("ID {$photo->id}: {$result['reason']}", null, 'v');
                    } catch (Throwable $exception) {
                        $failed++;
                        $this->warn("ID {$photo->id}: {$exception->getMessage()}");
                    }
                    if ($pause > 0) {
                        usleep($pause * 1000);
                    }
                }
                unset($photos);
                $this->line("Attempted: {$attempted} | Processed: {$processed} | Skipped: {$skipped} | Failed: {$failed} | Last ID: {$afterId}");
            }
            $saved = $before - $after;
            $percent = $before > 0 ? round($saved / $before * 100, 2) : 0;
            $this->line("Processed: {$processed} | Skipped: {$skipped} | Failed: {$failed}");
            $this->info("Before: {$before} bytes | After: {$after} bytes | Saved: {$saved} bytes ({$percent}%)");
            $this->line("Disk reclaimed: {$reclaimed} bytes".($dryRun ? ' (dry-run: no stored files changed)' : ''));
            $this->line('Elapsed: '.round(microtime(true) - $started, 2).' s | PHP peak: '.round(memory_get_peak_usage(true) / 1048576, 1).' MiB');
            $this->line("Resume: --after-id={$afterId}. Omit --after-id to retry failed rows; completed rows are skipped.");

            return $failed === 0 ? self::SUCCESS : self::FAILURE;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /** @return array{before: int, after: int, reclaimed: int, changed: bool, reason: string} */
    private function compress(object $photo, ProductImageOptimizer $optimizer, int $quality, bool $dryRun): array
    {
        $source = $optimizer->localPath($photo->path);
        $sourceHash = hash_file('sha256', $source);
        $image = $optimizer->prepare($source, $quality);
        $newPath = null;
        $committed = false;
        try {
            $result = ['before' => $image->beforeBytes, 'after' => $image->afterBytes, 'reclaimed' => 0, 'changed' => $image->temporary, 'reason' => $image->reason];
            if ($dryRun) {
                return $result;
            }
            $newPath = $image->temporary ? $optimizer->store($image) : null;
            if (! is_file($source) || hash_file('sha256', $source) !== $sourceHash) {
                throw new RuntimeException('File sumber berubah saat diproses; database tidak diubah.');
            }
            DB::transaction(function () use ($photo, $image, $newPath): void {
                $updated = DB::table('product_photos')->where('id', $photo->id)
                    ->where('path', $photo->path)->whereNull('storage_optimized_at')
                    ->update([
                        'path' => $newPath ?? $photo->path,
                        'storage_optimized_at' => now(),
                        'storage_optimization' => $image->reason,
                    ]);
                if ($updated !== 1) {
                    throw new RuntimeException('Record berubah atau dihapus saat diproses; hasil tidak diterapkan.');
                }
                if ($newPath !== null) {
                    DB::table('products')->where('id', $photo->product_id)->where('photo', $photo->path)
                        ->update(['photo' => $newPath]);
                }
            });
            $committed = true;
            if ($newPath !== null) {
                $result['reclaimed'] = -$image->afterBytes;
                $this->line("ID {$photo->id}: {$photo->path} -> {$newPath}");
                if (! $this->option('keep-originals')
                    && ! DB::table('product_photos')->where('path', $photo->path)->exists()
                    && ! DB::table('products')->where('photo', $photo->path)->exists()) {
                    if (Storage::disk('public')->delete($photo->path)) {
                        $result['reclaimed'] += $image->beforeBytes;
                    } else {
                        $this->warn("ID {$photo->id}: file lama belum terhapus; file baru sudah aktif.");
                    }
                }
            }

            return $result;
        } finally {
            $image->cleanup();
            if (! $committed && $newPath !== null) {
                $optimizer->discardUnreferenced($newPath);
            }
        }
    }

    private function integerOption(string $name, int $default, int $min, int $max): int
    {
        $value = $this->option($name) ?? $default;
        if (filter_var($value, FILTER_VALIDATE_INT) === false || $value < $min || $value > $max) {
            throw new RuntimeException("--{$name} harus bilangan bulat {$min}–{$max}.");
        }

        return (int) $value;
    }
}
