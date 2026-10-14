<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductFact extends Model
{
    protected $fillable = [
        'workspace_id', 'product_id', 'source_snapshot_id', 'key', 'source_value', 'confirmed_value',
        'status', 'confirmed_by_user_id', 'confirmed_at', 'source_checked_at',
    ];

    protected function casts(): array
    {
        return ['confirmed_at' => 'datetime', 'source_checked_at' => 'datetime'];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function confirmedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by_user_id');
    }
}
