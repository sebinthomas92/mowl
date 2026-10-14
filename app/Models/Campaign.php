<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** An objective, audience and dates tied to exact approved content versions and a live page. */
class Campaign extends Model
{
    public const STATUSES = [
        'planned' => ['', 'Planned'],
        'active' => ['ok', 'Active'],
        'paused' => ['warn', 'Paused'],
        'completed' => ['', 'Completed'],
    ];

    protected $fillable = [
        'workspace_id', 'product_id', 'page_id', 'follows_campaign_id', 'name', 'status', 'objective', 'offer', 'audience',
        'starts_on', 'ends_on', 'hypothesis', 'baseline', 'baseline_rate', 'traffic_source', 'pinned_page_version',
        'launched_at', 'launched_by_user_id', 'completed_at', 'created_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'starts_on' => 'date', 'ends_on' => 'date', 'launched_at' => 'datetime', 'completed_at' => 'datetime',
            'baseline_rate' => 'float', 'pinned_page_version' => 'integer',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function page(): BelongsTo
    {
        return $this->belongsTo(Page::class);
    }

    public function follows(): BelongsTo
    {
        return $this->belongsTo(self::class, 'follows_campaign_id');
    }

    public function followUps(): HasMany
    {
        return $this->hasMany(self::class, 'follows_campaign_id');
    }

    public function launchedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'launched_by_user_id');
    }

    public function deliverables(): HasMany
    {
        return $this->hasMany(CampaignDeliverable::class)->orderBy('position')->orderBy('id');
    }

    public function results(): HasMany
    {
        return $this->hasMany(CampaignResult::class)->orderByDesc('id');
    }

    public function isPlanned(): bool
    {
        return $this->status === 'planned';
    }

    /** "20–31 Oct 2026", "From 20 Oct 2026", or null when no dates are set. */
    public function dateRange(): ?string
    {
        [$start, $end] = [$this->starts_on, $this->ends_on];
        if (! $start && ! $end) {
            return null;
        }
        if ($start && ! $end) {
            return 'From '.$start->format('j M Y');
        }
        if (! $start) {
            return 'Until '.$end->format('j M Y');
        }
        if ($start->isSameDay($end)) {
            return $start->format('j M Y');
        }
        if ($start->isSameMonth($end)) {
            return $start->format('j').'–'.$end->format('j M Y');
        }

        return $start->format($start->isSameYear($end) ? 'j M' : 'j M Y').' – '.$end->format('j M Y');
    }
}
