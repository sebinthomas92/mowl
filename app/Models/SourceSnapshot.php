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

    /**
     * An owner approves the content they read. When a refetch changes that content, the approval
     * no longer covers it, so it is cleared and an owner has to approve the new source.
     */
    protected static function booted(): void
    {
        static::updating(function (self $snapshot): void {
            $changed = $snapshot->isDirty('content_hash') || $snapshot->isDirty('extracted_content') || $snapshot->isDirty('extracted_truth');
            if ($changed && $snapshot->getOriginal('approved_at') && ! $snapshot->isDirty('approved_at')) {
                $snapshot->approved_at = null;
                $snapshot->approved_by_user_id = null;
            }
        });
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
