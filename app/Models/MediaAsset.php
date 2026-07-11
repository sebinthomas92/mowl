<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MediaAsset extends Model
{
    protected $fillable = [
        'workspace_id', 'product_id', 'source_snapshot_id', 'type', 'disk', 'path', 'original_name',
        'mime_type', 'size_bytes', 'content_hash', 'status', 'derivatives', 'metadata', 'error_message', 'processed_at',
    ];

    protected function casts(): array
    {
        return ['derivatives' => 'array', 'metadata' => 'array', 'processed_at' => 'datetime'];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function sourceSnapshot(): BelongsTo
    {
        return $this->belongsTo(SourceSnapshot::class);
    }
}
