<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class BannerGenerationBatch extends Model
{
    protected $fillable = [
        'workspace_id', 'campaign_pack_id', 'campaign_pack_version_id', 'requested_by_user_id',
        'kind', 'included_key', 'requested_count', 'credit_cost', 'status', 'provider', 'model',
        'input_tokens', 'output_text_tokens', 'output_image_tokens', 'estimated_cost', 'cost_alert',
        'error_code', 'error_message', 'started_at', 'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'cost_alert' => 'boolean',
            'estimated_cost' => 'decimal:6',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function campaignPack(): BelongsTo
    {
        return $this->belongsTo(CampaignPack::class);
    }

    public function campaignPackVersion(): BelongsTo
    {
        return $this->belongsTo(CampaignPackVersion::class);
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_user_id');
    }

    public function creatives(): HasMany
    {
        return $this->hasMany(BannerCreative::class);
    }

    public function creditEvent(): HasOne
    {
        return $this->hasOne(WorkspaceCredit::class);
    }
}
