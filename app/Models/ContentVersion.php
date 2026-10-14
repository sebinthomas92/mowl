<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

class ContentVersion extends Model
{
    protected $fillable = [
        'content_item_id', 'version', 'body', 'note', 'status', 'campaign_pack_version_id', 'created_by_user_id',
        'reviewed_by_user_id', 'reviewed_by_name', 'submitted_at', 'reviewed_at',
    ];

    protected function casts(): array
    {
        return ['body' => 'array', 'submitted_at' => 'datetime', 'reviewed_at' => 'datetime'];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(ContentItem::class, 'content_item_id');
    }

    public function comments(): HasMany
    {
        return $this->hasMany(ContentComment::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by_user_id');
    }

    protected static function booted(): void
    {
        static::updating(function (self $version): void {
            if ($version->getOriginal('status') === 'approved') {
                throw new LogicException('Approved content versions are immutable.');
            }
        });
        static::deleting(function (self $version): void {
            if ($version->status === 'approved') {
                throw new LogicException('Approved content versions are immutable.');
            }
        });
    }
}
