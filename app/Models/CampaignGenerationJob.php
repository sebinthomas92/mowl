<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CampaignGenerationJob extends Model
{
    protected $fillable = [
        'workspace_id', 'campaign_pack_id', 'source_snapshot_id', 'status', 'phase', 'provider', 'model',
        'analysis_mode', 'section', 'base_version', 'credit_cost', 'attempts', 'cache_hit', 'input_tokens', 'cached_input_tokens',
        'output_tokens', 'estimated_cost', 'cost_alert', 'provider_request_id', 'error_code', 'error_message',
        'provider_latency_ms',
        'started_at', 'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'cache_hit' => 'boolean',
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

    public function sourceSnapshot(): BelongsTo
    {
        return $this->belongsTo(SourceSnapshot::class);
    }
}
