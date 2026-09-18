<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductAttribute extends Model
{
    public const SOURCE_DESCRIPTION_PARSER = 'description_parser';

    public const SOURCE_MANUAL = 'manual';

    public const SOURCE_VISION = 'vision';

    public const SOURCE_OCR = 'ocr';

    protected $guarded = ['id'];

    protected $casts = [
        'confidence' => 'float',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
