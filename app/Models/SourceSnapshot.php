<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SourceSnapshot extends Model
{
    protected $fillable = [
        'product_id', 'type', 'url', 'title', 'canonical_url', 'content_hash', 'status', 'extracted_content',
        'extracted_truth', 'error_message', 'fetched_at', 'approved_by_user_id', 'approved_at', 'refreshed_from_snapshot_id',
    ];

    protected function casts(): array
    {
        return ['extracted_truth' => 'array', 'fetched_at' => 'datetime', 'approved_at' => 'datetime'];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
