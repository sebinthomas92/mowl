<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContentClientLink extends Model
{
    protected $fillable = [
        'workspace_id', 'product_id', 'created_by_user_id', 'client_name', 'client_email', 'token_hash',
        'expires_at', 'revoked_at', 'last_used_at',
    ];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'revoked_at' => 'datetime', 'last_used_at' => 'datetime'];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function isActive(): bool
    {
        return ! $this->revoked_at && $this->expires_at->isFuture();
    }

    public static function findActive(string $token): ?self
    {
        $link = self::query()->where('token_hash', hash('sha256', $token))->first();

        return $link?->isActive() ? $link : null;
    }
}
