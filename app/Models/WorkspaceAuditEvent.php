<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class WorkspaceAuditEvent extends Model
{
    protected $fillable = [
        'workspace_id', 'actor_user_id', 'event', 'subject_type', 'subject_id', 'reason', 'metadata',
    ];

    protected function casts(): array
    {
        return ['metadata' => 'array'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Workspace audit events are immutable.'));
        static::deleting(fn () => throw new LogicException('Workspace audit events are immutable.'));
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
