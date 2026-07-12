<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CampaignJobEvent extends Model
{
    protected $fillable = ['campaign_generation_job_id', 'type', 'phase', 'metadata'];

    protected function casts(): array
    {
        return ['metadata' => 'array'];
    }

    public function campaignGenerationJob(): BelongsTo
    {
        return $this->belongsTo(CampaignGenerationJob::class);
    }
}
