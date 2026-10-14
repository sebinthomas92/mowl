<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A content item a campaign uses. Launching pins the approved version it runs with. */
class CampaignDeliverable extends Model
{
    protected $fillable = ['campaign_id', 'content_item_id', 'position', 'pinned_version'];

    protected function casts(): array
    {
        return ['pinned_version' => 'integer'];
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    public function contentItem(): BelongsTo
    {
        return $this->belongsTo(ContentItem::class);
    }
}
