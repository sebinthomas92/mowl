<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Page extends Model
{
    /** publish_state => [tag tone, label] */
    public const STATES = [
        'idle' => ['', 'Not published'],
        'publishing' => ['rev', 'Publishing'],
        'failed' => ['bad', 'Publish failed'],
        'verifying' => ['rev', 'Verification pending'],
        'live' => ['ok', 'Live · verified'],
    ];

    protected $fillable = [
        'workspace_id', 'product_id', 'store_connection_id', 'name', 'slug', 'headline', 'draft_version', 'live_version',
        'publish_state', 'last_error', 'remote_id', 'remote_url', 'verified_at', 'created_by_user_id',
    ];

    protected function casts(): array
    {
        return ['verified_at' => 'datetime', 'draft_version' => 'integer', 'live_version' => 'integer'];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function storeConnection(): BelongsTo
    {
        return $this->belongsTo(StoreConnection::class);
    }

    public function sections(): HasMany
    {
        return $this->hasMany(PageSection::class)->orderBy('position')->orderBy('id');
    }

    public function versions(): HasMany
    {
        return $this->hasMany(PageVersion::class);
    }

    public function attempts(): HasMany
    {
        return $this->hasMany(PublishAttempt::class);
    }

    /** The draft has been frozen by a publish, so the next edit starts a new draft version. */
    public function draftIsFrozen(): bool
    {
        return $this->versions()->where('version', $this->draft_version)->exists();
    }

    public function isBusy(): bool
    {
        return in_array($this->publish_state, ['publishing', 'verifying'], true);
    }
}
