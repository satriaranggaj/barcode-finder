<?php

namespace App\Services;

use App\Models\ProductPhoto;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

class VisualRepresentation
{
    public function extract(string $path): array
    {
        $stream = fopen($path, 'rb');
        try {
            $data = Http::connectTimeout(3)->timeout(config('visual_search.timeout'))
                ->attach('image', $stream, basename($path))
                ->post(rtrim(config('services.ai.url'), '/').'/features')->throw()->json();
        } finally {
            fclose($stream);
        }
        if (($data['pipeline'] ?? null) !== config('visual_search.pipeline')
            || ($data['model_revision'] ?? null) !== '3d74acf9a28c67741b2f4f2ea7635f0aaf6f0268'
            || ($data['object_model_sha256'] ?? null) !== '309c8469258dda742793dce0ebea8e6dd393174f89934733ecc8b14c76f4ddd8') {
            throw new \RuntimeException('Incompatible visual representation.');
        }
        $vector = $data['embedding'] ?? [];
        if (! is_array($vector) || count($vector) !== 512) {
            throw new \RuntimeException('Invalid vector dimensions.');
        }
        $norm = 0;
        foreach ($vector as $value) {
            if (! is_numeric($value) || ! is_finite((float) $value)) {
                throw new \RuntimeException('Invalid vector value.');
            }
            $norm += $value * $value;
        }
        if (abs($norm - 1) > .01) {
            throw new \RuntimeException('Unnormalized vector.');
        }
        foreach (['color' => 32, 'texture' => 16, 'pattern' => 32, 'shape' => 2] as $name => $size) {
            if (! array_key_exists($name, $data['features'] ?? [])) {
                throw new \RuntimeException('Missing visual features.');
            }
            $values = $data['features'][$name];
            if ($values === null) {
                continue;
            }
            if (! is_array($values) || count($values) !== $size) {
                throw new \RuntimeException('Invalid visual features.');
            }
            foreach ($values as $value) {
                if (! is_numeric($value) || ! is_finite((float) $value) || $value < 0 || $value > 1) {
                    throw new \RuntimeException('Invalid descriptor value.');
                }
            }
        }

        return $data;
    }

    public function index(ProductPhoto $photo): void
    {
        $path = Storage::disk('public')->path($photo->path);
        $hash = hash_file('sha256', $path);
        $data = $this->extract($path);
        DB::transaction(function () use ($photo, $path, $hash, $data): void {
            $current = ProductPhoto::query()->lockForUpdate()->find($photo->id);
            if (! $current || $current->path !== $photo->path || hash_file('sha256', $path) !== $hash) {
                throw new \RuntimeException('Photo changed during indexing.');
            }
            DB::table('visual_references')->updateOrInsert(['photo_id' => $photo->id], [
                'pipeline' => $data['pipeline'], 'source_path' => $photo->path, 'source_hash' => $hash,
                'features' => json_encode($data['features'], JSON_THROW_ON_ERROR),
                'embedding' => '['.implode(',', $data['embedding']).']',
                'created_at' => now(), 'updated_at' => now(),
            ]);
        });
    }
}
