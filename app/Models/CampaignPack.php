<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class CampaignPack extends Model
{
    protected $fillable = ['product_id', 'source_snapshot_id', 'name', 'status', 'analysis_mode', 'credit_cost', 'current_version', 'estimated_cost'];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function sourceSnapshot(): BelongsTo
    {
        return $this->belongsTo(SourceSnapshot::class);
    }

    public function versions(): HasMany
    {
        return $this->hasMany(CampaignPackVersion::class);
    }

    public function generationJobs(): HasMany
    {
        return $this->hasMany(CampaignGenerationJob::class);
    }

    public function shares(): HasMany
    {
        return $this->hasMany(CampaignPackShare::class);
    }

    public function latestGenerationJob(): HasOne
    {
        return $this->hasOne(CampaignGenerationJob::class)->latestOfMany();
    }
}
