<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/** A frozen page draft, created when it is first published. Never changes afterwards. */
class PageVersion extends Model
{
    protected $fillable = ['page_id', 'version', 'snapshot', 'created_by_user_id'];

    protected function casts(): array
    {
        return ['snapshot' => 'array'];
    }

    public function page(): BelongsTo
    {
        return $this->belongsTo(Page::class);
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Published page versions are immutable.'));
    }
}
