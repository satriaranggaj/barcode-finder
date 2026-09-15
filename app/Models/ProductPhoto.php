<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductPhoto extends Model
{
    protected $fillable = [
        'product_id',
        'path',
        'embedding',
        'crop',
        'selection_source',
        'selection_verified',
    ];

    protected $casts = [
        'crop' => 'array',
        'selection_verified' => 'boolean',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
