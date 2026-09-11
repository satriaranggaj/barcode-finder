<?php

namespace App\Services;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class ImageFeatureClient
{
    public const VERSION = 'object-v2';

    public const MODEL = 'openai/clip-vit-base-patch32';

    public const MODEL_REVISION = '3d74acf9a28c67741b2f4f2ea7635f0aaf6f0268';

    private function request(string $path, string $endpoint, array $fields): array
    {
        $stream = fopen($path, 'rb');
        if ($stream === false) {
            throw new RuntimeException('Image could not be opened.');
        }
        try {
            // No automatic retry of a consumed multipart stream. Maintenance can retry rows.
            $data = Http::connectTimeout(3)->timeout(45)
                ->attach('image', $stream, 'image')
                ->post(rtrim((string) config('services.ai.url'), '/').$endpoint, $fields)
                ->throw()->json();
        } finally {
            fclose($stream);
        }

        return $data;
    }

    public function descriptors(string $path, array $signals): array
    {
        $data = $this->request($path, '/descriptors', ['pipeline' => self::VERSION, 'signals' => implode(',', $signals)]);
        if (($data['version'] ?? null) !== self::VERSION || ($data['embedding_model'] ?? null) !== self::MODEL
            || ($data['model_revision'] ?? null) !== self::MODEL_REVISION || ! is_array($data['descriptors'] ?? null)) {
            throw new RuntimeException('Incompatible descriptor response.');
        }

        return $data['descriptors'];
    }

    public function extract(string $path, bool $details = true): array
    {
        $data = $this->request($path, '/features', ['pipeline' => self::VERSION, 'details' => $details ? 'true' : 'false']);
        $vector = $data['embedding'] ?? null;
        if (($data['version'] ?? null) !== self::VERSION || ($data['embedding_model'] ?? null) !== self::MODEL
            || ($data['model_revision'] ?? null) !== self::MODEL_REVISION
            || ! is_array($vector) || count($vector) !== 512 || ! array_is_list($vector)) {
            throw new RuntimeException('Incompatible image feature response.');
        }
        $norm = 0.0;
        foreach ($vector as $value) {
            if (! is_numeric($value) || ! is_finite((float) $value)) {
                throw new RuntimeException('Invalid image vector.');
            }
            $norm += $value * $value;
        }
        if ($norm < 0.99 || $norm > 1.01 || ! is_array($data['preprocessing'] ?? null)) {
            throw new RuntimeException('Invalid normalized image features.');
        }

        return $data;
    }

    public function store(int $photoId, string $path, string $hash, array $data): void
    {
        $metadata = $data;
        unset($metadata['embedding']);
        DB::table('product_photo_features')->upsert([[
            'product_photo_id' => $photoId,
            'version' => self::VERSION,
            'source_path' => $path,
            'source_sha256' => $hash,
            'embedding' => '['.implode(',', $data['embedding']).']',
            'metadata' => json_encode($metadata, JSON_THROW_ON_ERROR),
            'processed_at' => now(),
        ]], ['product_photo_id', 'version'], ['source_path', 'source_sha256', 'embedding', 'metadata', 'processed_at']);
    }

    public function pending(): Builder
    {
        return DB::table('product_photos')->whereNotExists(function ($query): void {
            $query->selectRaw('1')->from('product_photo_features')
                ->whereColumn('product_photo_features.product_photo_id', 'product_photos.id')
                ->whereColumn('product_photo_features.source_path', 'product_photos.path')
                ->where('version', self::VERSION);
        });
    }
}
