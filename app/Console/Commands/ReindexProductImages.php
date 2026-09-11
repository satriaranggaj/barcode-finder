<?php

namespace App\Console\Commands;

use App\Services\ImageFeatureClient;
use App\Services\ProductImageOptimizer;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

#[Signature('products:reindex-images {--dry-run} {--limit=} {--chunk=100} {--after-id=0} {--sleep=100}')]
#[Description('Explicitly build versioned image features without changing photos or legacy embeddings')]
class ReindexProductImages extends Command
{
    public function handle(ImageFeatureClient $client, ProductImageOptimizer $optimizer): int
    {
        $chunk = filter_var($this->option('chunk'), FILTER_VALIDATE_INT);
        $after = filter_var($this->option('after-id'), FILTER_VALIDATE_INT);
        $sleep = filter_var($this->option('sleep'), FILTER_VALIDATE_INT);
        $limit = $this->option('limit') === null ? PHP_INT_MAX : filter_var($this->option('limit'), FILTER_VALIDATE_INT);
        if ($chunk < 1 || $chunk > 1000 || $after === false || $after < 0 || $sleep === false || $sleep < 0 || $sleep > 60000 || $limit < 1) {
            $this->error('Invalid limit/chunk/after-id/sleep.');

            return self::FAILURE;
        }
        $lock = fopen(storage_path('framework/products-reindex-images.lock'), 'c');
        if ($lock === false || ! flock($lock, LOCK_EX | LOCK_NB)) {
            if (is_resource($lock)) {
                fclose($lock);
            }
            $this->error('Another image reindex is running.');

            return self::FAILURE;
        }
        $attempted = $processed = $failed = 0;
        $started = microtime(true);
        try {
            DB::connection()->disableQueryLog();
            $through = (int) DB::table('product_photos')->max('id');
            $this->info(($this->option('dry-run') ? 'DRY RUN' : 'APPLY').' | version='.ImageFeatureClient::VERSION.' | through-id='.$through);
            while ($attempted < $limit) {
                $rows = $client->pending()->where('id', '>', $after)->where('id', '<=', $through)
                    ->orderBy('id')->limit(min($chunk, $limit - $attempted))->get(['id', 'path']);
                if ($rows->isEmpty()) {
                    break;
                }
                foreach ($rows as $row) {
                    $after = $row->id;
                    $attempted++;
                    try {
                        $source = $optimizer->localPath($row->path);
                        $hash = hash_file('sha256', $source);
                        $data = $client->extract($source);
                        if (! hash_equals($hash, hash_file('sha256', $source))) {
                            throw new RuntimeException('Source changed during inference.');
                        }
                        if (! $this->option('dry-run')) {
                            DB::transaction(function () use ($row, $hash, $data, $client): void {
                                $photo = DB::table('product_photos')->where('id', $row->id)->lockForUpdate()->first(['path']);
                                if ($photo === null || $photo->path !== $row->path) {
                                    throw new RuntimeException('Photo changed during inference.');
                                }
                                $client->store($row->id, $row->path, $hash, $data);
                            });
                        }
                        $processed++;
                        $this->line('ID '.$row->id.': '.$data['preprocessing']['mode']);
                    } catch (\Throwable $error) {
                        $failed++;
                        $this->warn('ID '.$row->id.': failed (retryable)');
                        Log::warning('image_reindex_failed', ['photo_id' => $row->id, 'exception' => $error::class]);
                    }
                    if ($sleep) {
                        usleep($sleep * 1000);
                    }
                }
            }
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
        $this->info("Attempted: {$attempted} | Processed: {$processed} | Failed: {$failed} | Last ID: {$after}");
        $this->line('Elapsed: '.round(microtime(true) - $started, 2).' s | PHP peak: '.round(memory_get_peak_usage(true) / 1048576).' MiB');
        $this->line("Resume: --after-id={$after}. Omit --after-id to retry failed rows; completed rows are skipped.");

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
