<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CampaignPackVersion extends Model
{
    protected $fillable = ['campaign_pack_id', 'version', 'content', 'evidence', 'compliance_flags', 'generator'];

    protected function casts(): array
    {
        return ['content' => 'array', 'evidence' => 'array', 'compliance_flags' => 'array'];
    }

    public function campaignPack(): BelongsTo
    {
        return $this->belongsTo(CampaignPack::class);
    }
}
