<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CampaignPackShare extends Model
{
    protected $fillable = ['campaign_pack_id', 'campaign_pack_version_id', 'workspace_id', 'created_by_user_id', 'token', 'expires_at', 'revoked_at'];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'revoked_at' => 'datetime'];
    }

    public function version(): BelongsTo
    {
        return $this->belongsTo(CampaignPackVersion::class, 'campaign_pack_version_id');
    }
}
