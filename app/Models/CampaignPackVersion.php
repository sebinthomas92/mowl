<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

class CampaignPackVersion extends Model
{
    protected $fillable = ['campaign_pack_id', 'version', 'content', 'evidence', 'compliance_flags', 'generator', 'review_status', 'reviewed_by_user_id', 'reviewed_at', 'review_note'];

    protected function casts(): array
    {
        return ['content' => 'array', 'evidence' => 'array', 'compliance_flags' => 'array', 'reviewed_at' => 'datetime'];
    }

    public function campaignPack(): BelongsTo
    {
        return $this->belongsTo(CampaignPack::class);
    }

    public function comments(): HasMany
    {
        return $this->hasMany(CampaignPackVersionComment::class);
    }

    protected static function booted(): void
    {
        static::updating(function (self $version): void {
            if ($version->getOriginal('review_status') === 'approved') {
                throw new LogicException('Approved campaign-pack versions are immutable.');
            }
        });
        static::deleting(function (self $version): void {
            if ($version->review_status === 'approved') {
                throw new LogicException('Approved campaign-pack versions are immutable.');
            }
        });
    }
}
