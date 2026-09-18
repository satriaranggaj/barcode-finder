<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SearchFeedback extends Model
{
    public function confirmedProduct(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'confirmed_product_id');
    }

    protected $table = 'search_feedback';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['crop' => 'array', 'evidence' => 'array', 'candidates' => 'array',
            'score_gap' => 'float', 'reference_eligible' => 'boolean',
            'training_exported_at' => 'datetime'];
    }
}
