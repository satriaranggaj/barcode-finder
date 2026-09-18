<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class ProductPhoto extends Model
{
    protected $fillable = [
        'product_id',
        'path',
        'embedding',
        'master_path',
        'thumbnail_path',
        'crop',
        'selection_source',
        'selection_verified',
        'source',
        'photo_hash',
        'index_status',
        'disk',
        'optimization_status',
    ];

    protected $casts = [
        'crop' => 'array',
        'selection_verified' => 'boolean',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function diskName(): string
    {
        return $this->disk ?: config('product_images.disk');
    }

    public function getUrlAttribute(): string
    {
        return $this->imageUrl($this->path);
    }

    public function getThumbnailUrlAttribute(): string
    {
        return $this->imageUrl($this->thumbnail_path ?: $this->path);
    }

    private function imageUrl(string $path): string
    {
        $storage = Storage::disk($this->diskName());

        return $this->diskName() === 'public' ? $storage->url($path) : $storage->temporaryUrl($path, now()->addMinutes(15));
    }
}
