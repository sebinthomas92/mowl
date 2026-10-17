<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A store that pages publish to: a WooCommerce (WordPress) site, or the built-in sandbox store. */
class StoreConnection extends Model
{
    public const DRIVERS = ['woocommerce' => 'WooCommerce', 'shopify' => 'Shopify', 'sandbox' => 'Sandbox store'];

    protected $fillable = [
        'workspace_id', 'brand_id', 'name', 'driver', 'site_url', 'api_base', 'username', 'secret', 'status',
        'last_error', 'has_woocommerce', 'media', 'checked_at', 'created_by_user_id',
    ];

    protected $hidden = ['secret'];

    protected function casts(): array
    {
        return ['secret' => 'encrypted', 'media' => 'array', 'has_woocommerce' => 'boolean', 'checked_at' => 'datetime'];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    public function pages(): HasMany
    {
        return $this->hasMany(Page::class);
    }

    public function isConnected(): bool
    {
        return $this->status === 'connected';
    }

    /** Identifies the store a remote page id belongs to; changes when the store address changes. */
    public function remoteKey(): string
    {
        return hash('sha256', $this->id.'|'.$this->driver.'|'.rtrim(strtolower((string) $this->site_url), '/'));
    }

    public function label(): string
    {
        return match ($this->driver) {
            'sandbox' => 'Sandbox store',
            'shopify' => 'Shopify',
            default => $this->has_woocommerce ? 'WooCommerce' : 'WordPress',
        };
    }
}
