<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class ContentItem extends Model
{
    public const STATUSES = [
        'draft' => ['', 'Draft'],
        'review' => ['rev', 'In review'],
        'changes' => ['warn', 'Changes requested'],
        'approved' => ['ok', 'Approved'],
    ];

    public const TYPES = ['image' => 'Images', 'video' => 'Video scripts', 'copy' => 'Copy'];

    protected $fillable = [
        'workspace_id', 'product_id', 'campaign_pack_id', 'banner_creative_id', 'section', 'type', 'title',
        'status', 'current_version', 'synced_pack_version', 'variation_of_id', 'created_by_user_id',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function campaignPack(): BelongsTo
    {
        return $this->belongsTo(CampaignPack::class);
    }

    public function bannerCreative(): BelongsTo
    {
        return $this->belongsTo(BannerCreative::class);
    }

    public function variationOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'variation_of_id');
    }

    public function versions(): HasMany
    {
        return $this->hasMany(ContentVersion::class);
    }

    public function latestVersion(): HasOne
    {
        return $this->hasOne(ContentVersion::class)->ofMany('version', 'max');
    }

    /** A section of its launch pack, as opposed to a banner or a free-standing variation. */
    public function isPackSection(): bool
    {
        return $this->campaign_pack_id && $this->section && ! str_starts_with($this->section, 'banner.');
    }
}
