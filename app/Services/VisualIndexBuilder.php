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
                // Precise blame for a single changed reference: only that row
                // waits for a rebuild while the rest of the batch stays
                // retryable (failed), so one edited photo cannot park nine
                // innocent photos in rebuild-required with it.
                $blamedPhoto = $this->changedImagePhotoId($error->getMessage());
                if ($blamedPhoto !== null && ! in_array($blamedPhoto, $writtenPhotos, true)) {
                    $blamedPhoto = null;
                }
                $retryable = $blamedPhoto === null ? [] : array_values(array_diff($writtenPhotos, [$blamedPhoto]));
                if ($blamedPhoto !== null) {
                    ProductPhoto::whereKey($blamedPhoto)->update(['index_status' => 'rebuild-required']);
                } elseif ($writtenPhotos !== []) {
                    ProductPhoto::whereKey($writtenPhotos)->update(['index_status' => 'rebuild-required']);
                }
                if ($retryable !== []) {
                    ProductPhoto::whereKey($retryable)->where('index_status', 'indexing')->update(['index_status' => 'failed']);
                }
                // Store the TAIL: the actual ValueError sits at the end of
                // the message (head is only INFO preamble from model load).
                $tail = substr($error->getMessage(), -500);
                Cache::forever('visual-index-rebuild-required', $tail !== '' ? $tail : $error->getMessage());
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
     * Extract the catalog photo id blamed by a
     * "Changed image/metadata: <SKU>/<id>.<ext>" failure, if any.
     * verified-* references belong to search feedback (left pending).
     */
    public function changedImagePhotoId(string $message): ?int
    {
        if (! preg_match('/Changed image\/metadata:\s*(\S+?); use --rebuild/', $message, $matches)) {
            return null;
        }
        $base = pathinfo($matches[1], PATHINFO_FILENAME);
        if (str_starts_with($base, 'verified-') || ! ctype_digit($base)) {
            return null;
        }

        return (int) $base;
    }

    /**
     * Workspaces older than the configured retention, listed for inspection
     * (dry-run) or deletion. Each entry: name, path, size_mb, age_hours.
     * Only direct UUID-named children of index-builds are ever listed;
     * symlinks and unrelated entries are skipped, never followed.
     */
    public function staleWorkspaceCandidates(): array
    {
        $days = (int) config('retrieval.index_export_retention_days', 3);
        if ($days <= 0) {
            return [];
        }
        try {
            $base = Storage::disk('local')->path('index-builds');
        } catch (\Throwable $error) {
            report($error);

            return [];
        }
        if (! is_dir($base) || is_link($base)) {
            return [];
        }
        try {
            $entries = scandir($base);
        } catch (\Throwable $error) {
            report($error);

            return [];
        }
        if (! is_array($entries)) {
            return [];
        }
        $cutoff = time() - $days * 86400;
        $candidates = [];
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            if (! preg_match('/^[0-9a-f-]{36}$/D', $entry)) {
                continue;
            }
            $directory = $base.DIRECTORY_SEPARATOR.$entry;
            try {
                if (is_link($directory) || ! is_dir($directory)) {
                    continue;
                }
                $modified = @filemtime($directory);
                if ($modified !== false && (int) $modified > $cutoff) {
                    continue;
                }
                $candidates[] = ['name' => $entry, 'path' => $directory,
                    'size_mb' => round($this->directorySize($directory) / 1048576, 1),
                    'age_hours' => $modified === false ? null : round((time() - (int) $modified) / 3600, 1)];
            } catch (\Throwable $error) {
                report($error);

                continue;
            }
        }

        return $candidates;
    }

    /**
     * Remove one UUID workspace. Validates location before deleting;
     * returns false (never throws) when unsafe or unremovable.
     */
    public function removeWorkspace(string $path): bool
    {
        try {
            $base = realpath(Storage::disk('local')->path('index-builds'));
            if ($base === false || ! preg_match('/^[0-9a-f-]{36}$/D', basename($path))) {
                return false;
            }
            $real = realpath($path);
            if ($real === false || dirname($real) !== $base || is_link($path)) {
                return false;
            }
            $this->removeDirectory($real);
        } catch (\Throwable $error) {
            report($error);

            return false;
        }

        return ! is_dir($path);
    }

    /**
     * Remove crashed-build workspaces older than the configured retention.
     * Maintenance only: this method NEVER throws, so a root-owned or
     * unreadable leftover can report-and-continue instead of failing the
     * main indexing run.
     */
    public function pruneStaleWorkspaces(): int
    {
        $pruned = 0;
        foreach ($this->staleWorkspaceCandidates() as $candidate) {
            if ($this->removeWorkspace($candidate['path'])) {
                $pruned++;
            }
        }

        return $pruned;
    }

    private function directorySize(string $directory): int
    {
        $total = 0;
        try {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::SELF_FIRST
            );
        } catch (\Throwable) {
            return 0;
        }
        foreach ($iterator as $file) {
            try {
                if ($file->isLink()) {
                    continue;
                }
                if ($file->isFile()) {
                    $total += $file->getSize();
                }
            } catch (\Throwable) {
                continue;
            }
        }

        return $total;
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
    /**
     * Export the authoritative full snapshot used by every full rebuild
     * (manual command and automatic queue job share this path).
     *
     * @throws \RuntimeException
     */
    public function exportDataset(string $directory, bool $includeVerified): int
    {
        return app(VisualReferenceExporter::class)->export($directory, $includeVerified);
    }

    /**
     * Run the authoritative FULL build (`--rebuild`) for an exported
     * snapshot. Verified inclusion is decided at export time; the build
     * itself just reconstructs whatever the snapshot holds. Never touches
     * the serving generation until the new one is verified and CURRENT
     * flips atomically inside build_index.
     *
     * @param callable(string $type, string $buffer)|null $onOutput
     *
     * @throws \RuntimeException
     */
    public function rebuildFull(string $workspace, ?callable $onOutput = null): array
    {
        return $this->runBuilder($workspace, true, $onOutput);
    }

    private function runBuilder(string $workspace, bool $rebuild = false, ?callable $onOutput = null): array
    {
        $command = [config('retrieval.python'), '-B', '-m', 'app.scripts.build_index', '--dataset', $workspace];
        if ($rebuild) {
            $command[] = '--rebuild';
        }
        $process = new Process(
            $command,
            base_path('ai-service'),
            ['FAISS_INDEX_PATH' => config('retrieval.index_path'), 'RELEVANCE_POLICY' => ''],
        );
        $process->setTimeout(null);
        $process->run($onOutput);
        $output = trim($process->getOutput()."\n".$process->getErrorOutput());
        if (! $process->isSuccessful()) {
            // Propagate the Python reason (signature drift, changed
            // metadata, deleted refs) so the job can map rebuild-required
            // vs retriable failure instead of seeing a generic message.
            $reason = $output !== '' ? substr($output, -2000) : 'unknown error';
            $operation = $rebuild ? 'Index rebuild failed' : 'Index append failed';
            throw new \RuntimeException($operation.'; serving generation untouched. '.$reason);
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
        // Tolerant cleanup: per-entry failures (root-owned leftovers) must
        // surface as reports from the caller, never as an exception here —
        // the main indexing run depends on it via finally/prune paths.
        if (! is_dir($directory) || is_link($directory)) {
            return;
        }
        try {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            );
        } catch (\Throwable) {
            return;
        }
        foreach ($iterator as $file) {
            try {
                if ($file->isLink()) {
                    continue;
                }
                $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
            } catch (\Throwable) {
                continue;
            }
        }
        try {
            rmdir($directory);
        } catch (\Throwable) {
            // Partially removed (e.g. unreadable child): retried next run.
        }
    }
}
