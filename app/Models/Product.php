<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Product extends Model
{
    protected $fillable = [
        'sku',
        'description',
        'photo',
        'embedding',
    ];

    public function photos(): HasMany
    {
        return $this->hasMany(ProductPhoto::class);
    }
}
