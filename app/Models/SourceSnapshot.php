<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SourceSnapshot extends Model
{
    protected $fillable = ['product_id', 'type', 'url', 'content_hash', 'status', 'extracted_truth'];

    protected function casts(): array
    {
        return ['extracted_truth' => 'array'];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
