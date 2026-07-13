<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class WorkspaceSupportNote extends Model
{
    protected $fillable = ['workspace_id', 'author_user_id', 'body'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Support notes are append-only.'));
        static::deleting(fn () => throw new LogicException('Support notes are append-only.'));
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_user_id');
    }
}
