<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkspaceSubscription extends Model
{
    protected $fillable = ['workspace_id', 'stripe_customer_id', 'stripe_subscription_id', 'stripe_price_id', 'status', 'current_period_ends_at', 'canceled_at', 'stripe_updated_at'];

    protected function casts(): array
    {
        return ['current_period_ends_at' => 'datetime', 'canceled_at' => 'datetime', 'stripe_updated_at' => 'datetime'];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }
}
