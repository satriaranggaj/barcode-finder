<?php

namespace App\Services;

use App\Models\ProductPhoto;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class ProductImages
{
    public function store(mixed $image): array
    {
        $data = app(RetrievalClient::class)->send('prepare', $image, [
            'quality' => config('product_images.quality'), 'catalog_side' => config('product_images.catalog_side'),
            'thumbnail_side' => config('product_images.thumbnail_side'),
            'catalog_quality' => config('product_images.catalog_quality'),
            'thumbnail_quality' => config('product_images.thumbnail_quality'),
        ]);
        if (! in_array($data['status'] ?? '', ['optimized', 'preserved_pixel_limit', 'preserved_memory_budget'], true)
            || ! in_array($data['extension'] ?? '', ['jpg', 'png', 'webp'], true)) {
            throw new RuntimeException('Invalid prepared image response');
        }
        $disk = config('product_images.disk');
        $paths = [];
        try {
            foreach (['master', 'catalog', 'thumbnail'] as $variant) {
                if (! isset($data[$variant])) {
                    if ($variant === 'master') {
                        throw new RuntimeException('Missing master image');
                    }

                    continue;
                }
                $bytes = base64_decode($data[$variant], true);
                if (! $bytes || ! @getimagesizefromstring($bytes)) {
                    throw new RuntimeException('Invalid prepared pixels');
                }
                $path = app(StoragePaths::class)->productVariant($variant, $data['extension']);
                $paths[$variant] = $path;
                if (! Storage::disk($disk)->put($path, $bytes, ['visibility' => $disk === 'public' ? 'public' : 'private'])) {
                    throw new RuntimeException('Image storage failed');
                }
            }

            return ['disk' => $disk, 'master_path' => $paths['master'], 'path' => $paths['catalog'] ?? $paths['master'],
                'thumbnail_path' => $paths['thumbnail'] ?? null, 'optimization_status' => $data['status'],
                'photo_hash' => $data['photo_hash'] ?? null];
        } catch (\Throwable $error) {
            $this->deleteQuietly($disk, array_values($paths));
            throw $error;
        }
    }

    public function discard(array $photo): void
    {
        $this->deleteQuietly($photo['disk'], array_values(array_unique(array_filter([
            $photo['path'], $photo['master_path'], $photo['thumbnail_path'] ?? null,
        ]))));
    }

    public function discardPhoto(ProductPhoto $photo): void
    {
        $this->deleteQuietly($photo->diskName(), array_values(array_unique(array_filter([
            $photo->path, $photo->master_path, $photo->thumbnail_path,
        ]))));
    }

    /**
     * Cleanup must never mask the primary operation: failed deletes are
     * reported and left for maintenance instead of aborting the request.
     */
    private function deleteQuietly(string $disk, array $paths): void
    {
        try {
            Storage::disk($disk)->delete($paths);
        } catch (\Throwable $error) {
            report($error);
        }
    }
}
