<?php

namespace App\Services;

use App\Models\ProductPhoto;
use App\Models\SearchFeedback;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;

/**
 * Incremental FAISS generation builder shared by the artisan command and the
 * auto-indexing queue job. Never mutates the serving snapshot: it clones
 * CURRENT into a private workspace, appends only new references, verifies,
 * and atomically flips CURRENT (all inside app.scripts.build_index).
 */
class VisualIndexBuilder
{
    private ?Lock $heldLock = null;

    /**
     * Attempt the shared single-writer lock without blocking.
     * The instance is retained so release() uses the same owner token.
     */
    public function acquireLock(): bool
    {
        $lock = Cache::lock('visual-index-build', 86400);
        if (! $lock->get()) {
            return false;
        }
        $this->heldLock = $lock;

        return true;
    }

    public function releaseLock(): void
    {
        try {
            $this->heldLock?->release();
        } catch (\Throwable) {
            // Lock expiry already released it; nothing to do.
        }
        $this->heldLock = null;
        // Fallback for locks acquired before this instance existed
        // (defensive; normally a no-op since owner differs).
        try {
            Cache::lock('visual-index-build', 86400)->release();
        } catch (\Throwable) {
        }
    }

    /**
     * Append one catalog photo to a fresh generation. Idempotent: unchanged
     * references are skipped by image_id inside the builder, so re-running
     * for the same photo publishes an equivalent generation at most.
     *
     * Status ownership lives with the caller (job/batch): this method only
     * writes + builds and never touches index_status.
     *
     * @throws \RuntimeException
     */
    public function appendPhoto(ProductPhoto $photo): string
    {
        $photo->loadMissing('product');
        $product = $photo->product;
        if (! $product) {
            throw new \RuntimeException("Photo {$photo->id} has no product.");
        }
        $source = $photo->master_path ?: $photo->path;
        if (! is_string($source) || $source === '') {
            throw new \RuntimeException("Photo {$photo->id} has no source image.");
        }
        $stream = Storage::disk($photo->diskName())->readStream($source);
        if (! is_resource($stream)) {
            throw new \RuntimeException("Missing source image for photo {$photo->id}.");
        }
        if (! preg_match('/^[a-zA-Z0-9_-]+$/D', $product->sku)) {
            fclose($stream);
            throw new \RuntimeException("SKU cannot be represented safely as a folder: {$product->sku}.");
        }
        $workspace = Storage::disk('local')->path(app(StoragePaths::class)->indexBuild());
        try {
            $this->writeReference($workspace, $product->sku, (string) $photo->id, $source, $stream, [
                'product_id' => $photo->product_id,
                'description' => $product->description,
                'trusted_attributes' => ProductAttributes::trustedForExport($product),
                'source_photo_hash' => $photo->photo_hash,
                'crop' => $photo->crop,
                'selection_source' => $photo->selection_source,
                'selection_verified' => $photo->selection_verified,
                'source' => $photo->source ?: 'catalog',
                'capture_group' => 'reference-'.$photo->id,
            ]);
            $this->runBuilder($workspace);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
            $this->removeDirectory($workspace);
        }

        return (string) $photo->id;
    }

    /**
     * Append one eligible verified feedback as a confirmed_search reference.
     * Rows that are not (verified + eligible + live product) are rejected
     * before any file or index write. Never touches indexed_at; the caller
     * marks success.
     *
     * @throws \RuntimeException
     */
    public function appendFeedback(SearchFeedback $row): string
    {
        $row->loadMissing('confirmedProduct');
        $product = $row->confirmedProduct;
        if ($row->training_status !== 'verified' || ! $row->reference_eligible
            || ! $product || $product->sku !== $row->confirmed_sku) {
            throw new \RuntimeException("Feedback {$row->id} is not eligible for reference memory.");
        }
        $stream = Storage::disk($row->disk)->readStream($row->query_image_path);
        if (! is_resource($stream)) {
            throw new \RuntimeException("Missing verified image for feedback {$row->id}.");
        }
        if (! preg_match('/^[a-zA-Z0-9_-]+$/D', (string) $row->confirmed_sku)) {
            fclose($stream);
            throw new \RuntimeException('Unsafe confirmed SKU.');
        }
        $imageId = 'verified-'.$row->id;
        $workspace = Storage::disk('local')->path(app(StoragePaths::class)->indexBuild());
        try {
            $this->writeReference($workspace, $row->confirmed_sku, $imageId, $row->query_image_path, $stream, [
                'product_id' => $row->confirmed_product_id,
                'description' => $product->description,
                'trusted_attributes' => ProductAttributes::trustedForExport($product),
                'source_photo_hash' => $row->photo_hash,
                'crop' => $row->crop,
                'selection_source' => $row->selection_source,
                'selection_verified' => true,
                'source' => 'confirmed_search',
                'capture_group' => $row->evidence['capture_group'] ?? 'unknown-session',
            ], '.webp');
            $this->runBuilder($workspace);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
            $this->removeDirectory($workspace);
        }

        return $imageId;
    }

    /**
     * Coalesced incremental append: drain every pending photo + unindexed
     * eligible feedback into ONE private workspace and ONE builder run, then
     * publish ONE new generation. Ten simultaneous uploads therefore produce
     * one generation instead of ten sequential SigLIP/DINO loads.
     *
     * Idempotent: image_id dedup inside build_index skips already-present
     * references, so re-running publishes an equivalent generation at most.
     *
     * @return array{added:int, skipped:int, generation:?string, photos:list<int>, feedback:list<int>}
     *
     * @throws \RuntimeException
     */
    public function appendPending(int $limit = 50): array
    {
        $photos = ProductPhoto::with('product.attributes')
            ->whereIn('index_status', ['pending', 'failed', 'indexing', 'rebuild-required'])
            ->orderBy('id')->limit($limit)->get();
        // rebuild-required rows can never be appended (HNSW has no removal);
        // they wait for a full rebuild and must not block the batch.
        $photos = $photos->filter(fn ($photo) => $photo->index_status !== 'rebuild-required')->values();
        $feedbacks = SearchFeedback::with('confirmedProduct.attributes')
            ->where('training_status', 'verified')->where('reference_eligible', true)
            ->whereNull('indexed_at')->orderBy('id')->limit($limit)->get();

        if ($photos->isEmpty() && $feedbacks->isEmpty()) {
            return ['added' => 0, 'skipped' => 0, 'generation' => null, 'photos' => [], 'feedback' => []];
        }

        $photoIds = $photos->map(fn ($photo) => $photo->id)->all();
        if ($photoIds !== []) {
            ProductPhoto::whereKey($photoIds)->update(['index_status' => 'indexing']);
        }

        $workspace = Storage::disk('local')->path(app(StoragePaths::class)->indexBuild());
        $writtenPhotos = [];
        $writtenFeedback = [];
        try {
            foreach ($photos as $photo) {
                try {
                    $this->writeOnePhoto($workspace, $photo);
                    $writtenPhotos[] = $photo->id;
                } catch (\Throwable $error) {
                    // One corrupt upload must not block the other nine:
                    // park it as failed and keep the batch moving.
                    ProductPhoto::whereKey($photo->id)->update(['index_status' => 'failed']);
                    report($error);
                }
            }
            foreach ($feedbacks as $row) {
                try {
                    $this->writeOneFeedback($workspace, $row);
                    $writtenFeedback[] = $row->id;
                } catch (\Throwable $error) {
                    // Ineligible/gone rows stay pending=null so a later full
                    // build or retry can still pick them up; only log.
                    report($error);
                }
            }
            // Remove rows that failed to export from the success set.
            $writtenPhotos = array_values(array_diff($writtenPhotos,
                ProductPhoto::whereKey($writtenPhotos)->where('index_status', 'failed')->pluck('id')->all()));

            if ($writtenPhotos === [] && $writtenFeedback === []) {
                return ['added' => 0, 'skipped' => 0, 'generation' => null, 'photos' => [], 'feedback' => []];
            }

            $result = $this->runBuilder($workspace);

            if ($writtenPhotos !== []) {
                ProductPhoto::whereKey($writtenPhotos)->update(['index_status' => 'indexed']);
            }
            if ($writtenFeedback !== []) {
                SearchFeedback::whereKey($writtenFeedback)->update(['indexed_at' => now()]);
            }

            return ['added' => $result['added'] ?? 0, 'skipped' => $result['skipped'] ?? 0,
                'generation' => $result['generation'] ?? null, 'photos' => $writtenPhotos, 'feedback' => $writtenFeedback];
        } catch (\RuntimeException $error) {
            if ($this->isRebuildRequired($error->getMessage())) {
                if ($writtenPhotos !== []) {
                    ProductPhoto::whereKey($writtenPhotos)->update(['index_status' => 'rebuild-required']);
                }
                Cache::forever('visual-index-rebuild-required', substr($error->getMessage(), 0, 500));
            } else {
                if ($writtenPhotos !== []) {
                    ProductPhoto::whereKey($writtenPhotos)
                        ->where('index_status', 'indexing')->update(['index_status' => 'failed']);
                }
            }
            throw $error;
        } finally {
            $this->removeDirectory($workspace);
        }
    }

    /**
     * Mark every photo indexed: used only after a full --rebuild generation
     * publishes successfully (incremental appends mark their own rows).
     * Feedback is healed only when the rebuild actually included verified
     * references; otherwise pending confirmations stay pending.
     */
    public function markAllIndexed(bool $includeVerified = true): void
    {
        \Illuminate\Support\Facades\DB::table('product_photos')->update(['index_status' => 'indexed']);
        if ($includeVerified) {
            SearchFeedback::where('training_status', 'verified')->where('reference_eligible', true)
                ->whereNull('indexed_at')->update(['indexed_at' => now()]);
        }
        Cache::forget('visual-index-rebuild-required');
    }

    /**
     * Remove crashed-build workspaces older than a day. Only UUID-named
     * index-builds directories are ever touched.
     */
    public function pruneStaleWorkspaces(): int
    {
        $disk = Storage::disk('local');
        $pruned = 0;
        foreach ($disk->allDirectories('index-builds') as $directory) {
            $base = basename($directory);
            if (! preg_match('/^[0-9a-f-]{36}$/D', $base)) {
                continue;
            }
            if ($disk->lastModified($directory) < time() - 86400) {
                $this->removeDirectory($disk->path($directory));
                $pruned++;
            }
        }

        return $pruned;
    }

    public function isRebuildRequired(string $message): bool
    {
        $haystack = strtolower($message);

        return str_contains($haystack, 'use --rebuild')
            || str_contains($haystack, 'pipeline/model changed')
            || str_contains($haystack, 'preprocessing changed')
            || str_contains($haystack, 'changed image/metadata')
            || str_contains($haystack, 'references deleted')
            || str_contains($haystack, 'model dimension changed');
    }

    private function writeOnePhoto(string $workspace, ProductPhoto $photo): void
    {
        $photo->loadMissing('product');
        $product = $photo->product;
        if (! $product) {
            throw new \RuntimeException("Photo {$photo->id} has no product.");
        }
        $source = $photo->master_path ?: $photo->path;
        if (! is_string($source) || $source === '') {
            throw new \RuntimeException("Photo {$photo->id} has no source image.");
        }
        $stream = Storage::disk($photo->diskName())->readStream($source);
        if (! is_resource($stream)) {
            throw new \RuntimeException("Missing source image for photo {$photo->id}.");
        }
        try {
            if (! preg_match('/^[a-zA-Z0-9_-]+$/D', $product->sku)) {
                throw new \RuntimeException("SKU cannot be represented safely as a folder: {$product->sku}.");
            }
            $this->writeReference($workspace, $product->sku, (string) $photo->id, $source, $stream, [
                'product_id' => $photo->product_id,
                'description' => $product->description,
                'trusted_attributes' => ProductAttributes::trustedForExport($product),
                'source_photo_hash' => $photo->photo_hash,
                'crop' => $photo->crop,
                'selection_source' => $photo->selection_source,
                'selection_verified' => $photo->selection_verified,
                'source' => $photo->source ?: 'catalog',
                'capture_group' => 'reference-'.$photo->id,
            ]);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }

    private function writeOneFeedback(string $workspace, SearchFeedback $row): void
    {
        $row->loadMissing('confirmedProduct');
        $product = $row->confirmedProduct;
        if ($row->training_status !== 'verified' || ! $row->reference_eligible
            || ! $product || $product->sku !== $row->confirmed_sku) {
            throw new \RuntimeException("Feedback {$row->id} is not eligible for reference memory.");
        }
        $stream = Storage::disk($row->disk)->readStream($row->query_image_path);
        if (! is_resource($stream)) {
            throw new \RuntimeException("Missing verified image for feedback {$row->id}.");
        }
        try {
            if (! preg_match('/^[a-zA-Z0-9_-]+$/D', (string) $row->confirmed_sku)) {
                throw new \RuntimeException('Unsafe confirmed SKU.');
            }
            $this->writeReference($workspace, $row->confirmed_sku, 'verified-'.$row->id, $row->query_image_path, $stream, [
                'product_id' => $row->confirmed_product_id,
                'description' => $product->description,
                'trusted_attributes' => ProductAttributes::trustedForExport($product),
                'source_photo_hash' => $row->photo_hash,
                'crop' => $row->crop,
                'selection_source' => $row->selection_source,
                'selection_verified' => true,
                'source' => 'confirmed_search',
                'capture_group' => $row->evidence['capture_group'] ?? 'unknown-session',
            ], '.webp');
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }

    private function writeReference(string $workspace, string $sku, string $imageId, string $source, mixed $stream, array $sidecar, ?string $forceExtension = null): void
    {
        $folder = $workspace.DIRECTORY_SEPARATOR.$sku;
        if (! is_dir($folder) && ! mkdir($folder, 0700, true)) {
            throw new \RuntimeException('Cannot create build workspace.');
        }
        $extension = $forceExtension ?? strtolower(pathinfo($source, PATHINFO_EXTENSION));
        if (str_starts_with($extension, '.')) {
            $extension = substr($extension, 1);
        }
        if (! in_array($extension, ['jpg', 'jpeg', 'png', 'webp'], true)) {
            throw new \RuntimeException('Unsupported reference format.');
        }
        $target = fopen($folder.DIRECTORY_SEPARATOR.$imageId.'.'.$extension, 'xb');
        if (! is_resource($target)) {
            throw new \RuntimeException('Export target exists; refusing to overwrite.');
        }
        try {
            if (stream_copy_to_stream($stream, $target) === false) {
                throw new \RuntimeException('Export failed.');
            }
        } finally {
            fclose($target);
        }
        if (file_put_contents($folder.DIRECTORY_SEPARATOR.$imageId.'.json', json_encode($sidecar, JSON_THROW_ON_ERROR)) === false) {
            throw new \RuntimeException('Sidecar write failed.');
        }
    }

    /**
     * @return array{added:int, skipped:int, generation:?string}
     */
    private function runBuilder(string $workspace): array
    {
        $process = new Process(
            [config('retrieval.python'), '-B', '-m', 'app.scripts.build_index', '--dataset', $workspace],
            base_path('ai-service'),
            ['FAISS_INDEX_PATH' => config('retrieval.index_path'), 'RELEVANCE_POLICY' => ''],
        );
        $process->setTimeout(null);
        $process->run();
        $output = trim($process->getOutput()."\n".$process->getErrorOutput());
        if (! $process->isSuccessful()) {
            // Propagate the Python reason (signature drift, changed
            // metadata, deleted refs) so the job can map rebuild-required
            // vs retriable failure instead of seeing a generic message.
            $reason = $output !== '' ? substr($output, -2000) : 'unknown error';
            throw new \RuntimeException('Index append failed; serving generation untouched. '.$reason);
        }
        // build_index prints a dict like {'added': 1, ...}; parse best-effort.
        $parsed = ['added' => 0, 'skipped' => 0, 'generation' => null];
        if (preg_match('/\{.*\}/s', $output, $matches)) {
            $decoded = json_decode(str_replace("'", '"', $matches[0]), true);
            if (is_array($decoded)) {
                $parsed['added'] = (int) ($decoded['added'] ?? 0);
                $parsed['skipped'] = (int) ($decoded['skipped'] ?? 0);
                $parsed['generation'] = isset($decoded['generation']) ? (string) $decoded['generation'] : null;
            }
        }

        return $parsed;
    }

    private function removeDirectory(string $directory): void
    {
        if (! is_dir($directory)) {
            return;
        }
        collect(new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        ))->each(fn ($file) => $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname()));
        rmdir($directory);
    }
}
