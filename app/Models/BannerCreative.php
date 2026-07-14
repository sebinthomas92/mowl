<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BannerCreative extends Model
{
    protected $fillable = [
        'banner_generation_batch_id', 'campaign_pack_id', 'campaign_pack_version_id', 'sequence',
        'direction', 'layout', 'headline', 'supporting_text', 'cta', 'prompt', 'status', 'disk',
        'background_path', 'output_path', 'output_mime_type', 'width', 'height', 'size_bytes',
        'content_hash', 'provider', 'model', 'input_tokens', 'output_text_tokens',
        'output_image_tokens', 'estimated_cost', 'provider_request_id', 'provider_latency_ms',
        'attempts', 'error_code', 'error_message', 'started_at', 'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'estimated_cost' => 'decimal:6',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(BannerGenerationBatch::class, 'banner_generation_batch_id');
    }

    public function campaignPack(): BelongsTo
    {
        return $this->belongsTo(CampaignPack::class);
    }

    public function campaignPackVersion(): BelongsTo
    {
        return $this->belongsTo(CampaignPackVersion::class);
    }
}
