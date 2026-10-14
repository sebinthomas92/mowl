<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One import of results for a campaign: a CSV export or orders pulled from the store.
 *
 * daily holds one row per day: ['date' => 'Y-m-d', 'visits' => ?int, 'orders' => ?int, 'revenue' => ?float].
 */
class CampaignResult extends Model
{
    public const SOURCES = ['csv' => 'CSV import', 'store' => 'Store orders'];

    protected $fillable = [
        'campaign_id', 'workspace_id', 'source', 'source_label', 'window_start', 'window_end', 'currency', 'attribution',
        'visits', 'orders', 'revenue', 'daily', 'imported_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'window_start' => 'date', 'window_end' => 'date', 'daily' => 'array',
            'visits' => 'integer', 'orders' => 'integer', 'revenue' => 'float',
        ];
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    public function importedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'imported_by_user_id');
    }
}
