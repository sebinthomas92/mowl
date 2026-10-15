<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Schema;

class WorkspaceSubscription extends Model
{
    /** Stripe statuses that keep a subscribed workspace able to spend credits. past_due is the retry window. */
    public const GOOD_STANDING = ['active', 'trialing', 'past_due'];

    /** Statuses after which a workspace that once subscribed becomes read-only until it subscribes again. */
    public const LOCKED = ['canceled', 'unpaid', 'incomplete_expired', 'paused'];

    protected $fillable = [
        'workspace_id', 'stripe_customer_id', 'stripe_subscription_id', 'stripe_price_id', 'status',
        'current_period_ends_at', 'stripe_updated_at', 'canceled_at', 'cancel_at_period_end',
    ];

    protected function casts(): array
    {
        return [
            'current_period_ends_at' => 'datetime',
            'stripe_updated_at' => 'datetime',
            'canceled_at' => 'datetime',
            'cancel_at_period_end' => 'boolean',
        ];
    }

    /** Production runs migrations as a separate step; billing waits for its table. */
    public static function available(): bool
    {
        $container = app();
        if (! $container->bound('billing.available')) {
            $container->instance('billing.available', Schema::hasTable('workspace_subscriptions') && Schema::hasColumn('workspace_subscriptions', 'cancel_at_period_end'));
        }

        return $container->make('billing.available');
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function isSubscribed(): bool
    {
        return in_array($this->status, self::GOOD_STANDING, true);
    }

    public function isLocked(): bool
    {
        return in_array($this->status, self::LOCKED, true);
    }
}
