<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PublishAttempt extends Model
{
    protected $fillable = [
        'page_id', 'page_version_id', 'store_connection_id', 'action', 'status', 'error', 'remote_url', 'html',
        'verify_checks', 'user_id', 'finished_at', 'warning',
    ];

    protected function casts(): array
    {
        return ['finished_at' => 'datetime'];
    }

    public function page(): BelongsTo
    {
        return $this->belongsTo(Page::class);
    }

    public function pageVersion(): BelongsTo
    {
        return $this->belongsTo(PageVersion::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
