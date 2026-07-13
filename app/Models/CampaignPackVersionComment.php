<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CampaignPackVersionComment extends Model
{
    protected $fillable = ['campaign_pack_version_id', 'workspace_id', 'user_id', 'section', 'body'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
