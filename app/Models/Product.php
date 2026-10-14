<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Product extends Model
{
    protected $fillable = ['brand_id', 'name', 'price', 'summary'];

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    public function sourceSnapshots(): HasMany
    {
        return $this->hasMany(SourceSnapshot::class);
    }

    public function campaignPacks(): HasMany
    {
        return $this->hasMany(CampaignPack::class);
    }

    public function mediaAssets(): HasMany
    {
        return $this->hasMany(MediaAsset::class);
    }

    public function resourceLinks(): HasMany
    {
        return $this->hasMany(ProductResourceLink::class);
    }

    public function facts(): HasMany
    {
        return $this->hasMany(ProductFact::class);
    }

    public function hubShares(): HasMany
    {
        return $this->hasMany(ProductHubShare::class);
    }

    public function contentItems(): HasMany
    {
        return $this->hasMany(ContentItem::class);
    }

    public function campaigns(): HasMany
    {
        return $this->hasMany(Campaign::class);
    }

    public function pages(): HasMany
    {
        return $this->hasMany(Page::class);
    }
}
