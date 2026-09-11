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
        'storage_optimized_at',
        'storage_optimization',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
