<?php

namespace App\Services;

use Illuminate\Support\Str;

final class StoragePaths
{
    public function productVariant(string $variant, string $extension): string
    {
        return 'products/'.Str::uuid().'-'.$variant.'.'.$this->safeExtension($extension);
    }

    public function pendingQuery(): string
    {
        return 'search-pending/'.Str::uuid().'.webp';
    }

    public function verifiedReference(): string
    {
        return 'verified-search/'.Str::uuid().'.webp';
    }

    public function indexBuild(): string
    {
        return 'index-builds/'.Str::uuid();
    }

    public function safeExtension(string $extension): string
    {
        $normalized = strtolower(pathinfo($extension, PATHINFO_EXTENSION) ?: $extension);

        return match ($normalized) {
            'jpeg' => 'jpg',
            'jpg', 'png', 'webp' => $normalized,
            default => throw new \InvalidArgumentException("Unsupported image extension: {$extension}"),
        };
    }
}
