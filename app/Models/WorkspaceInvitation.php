<?php

namespace App\Models;

use App\Support\Membership;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkspaceInvitation extends Model
{
    protected $fillable = [
        'workspace_id', 'invited_by_user_id', 'email', 'token_hash', 'role', 'brand_id', 'expires_at', 'accepted_at',
    ];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'accepted_at' => 'datetime'];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    public function invitedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by_user_id');
    }

    public function isOpen(): bool
    {
        return $this->accepted_at === null && $this->expires_at->isFuture();
    }

    /** The workspace_user row an accepted invitation creates. */
    public function pivotAttributes(): array
    {
        return Membership::scopesAvailable() ? ['role' => $this->role, 'brand_id' => $this->brand_id] : ['role' => $this->role];
    }

    public static function findOpenToken(string $token): ?self
    {
        return static::query()
            ->where('token_hash', hash('sha256', $token))
            ->whereNull('accepted_at')
            ->where('expires_at', '>', now())
            ->first();
    }
}
