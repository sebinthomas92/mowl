<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PageSection extends Model
{
    /** kind => [label, accepts] where accepts says which content items can be bound. */
    public const KINDS = [
        'hero' => ['Hero', 'image'],
        'message' => ['Message', 'copy'],
        'offer' => ['Offer', 'copy'],
        'benefits' => ['Benefits', 'copy'],
        'specs' => ['Specs table', null],
        'cta' => ['Call to action', null],
    ];

    protected $fillable = ['page_id', 'position', 'kind', 'title', 'content_item_id', 'settings'];

    protected function casts(): array
    {
        return ['settings' => 'array'];
    }

    public function page(): BelongsTo
    {
        return $this->belongsTo(Page::class);
    }

    public function contentItem(): BelongsTo
    {
        return $this->belongsTo(ContentItem::class);
    }

    public function accepts(): ?string
    {
        return self::KINDS[$this->kind][1] ?? null;
    }
}
