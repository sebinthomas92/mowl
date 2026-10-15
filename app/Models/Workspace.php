<?php

namespace App\Models;

use App\Support\Membership;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Workspace extends Model
{
    protected $fillable = ['name'];

    public function brands(): HasMany
    {
        return $this->hasMany(Brand::class);
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'workspace_user')->withPivot(Membership::pivotColumns())->withTimestamps();
    }

    public function credits(): HasMany
    {
        return $this->hasMany(WorkspaceCredit::class);
    }

    public function invitations(): HasMany
    {
        return $this->hasMany(WorkspaceInvitation::class);
    }

    public function generationJobs(): HasMany
    {
        return $this->hasMany(CampaignGenerationJob::class);
    }

    public function bannerGenerationBatches(): HasMany
    {
        return $this->hasMany(BannerGenerationBatch::class);
    }

    public function auditEvents(): HasMany
    {
        return $this->hasMany(WorkspaceAuditEvent::class);
    }

    public function supportNotes(): HasMany
    {
        return $this->hasMany(WorkspaceSupportNote::class);
    }

    public function onboardingState(): HasOne
    {
        return $this->hasOne(WorkspaceOnboardingState::class);
    }

    public function creditBalance(): int
    {
        return (int) $this->credits()->sum('amount');
    }

    public const BILLING_LOCKED_MESSAGE = 'The plan for this workspace has ended. Restart it on the Billing page to generate again.';

    public function subscription(): HasOne
    {
        return $this->hasOne(WorkspaceSubscription::class);
    }

    public function billingSubscription(): ?WorkspaceSubscription
    {
        return WorkspaceSubscription::available() ? $this->subscription()->first() : null;
    }

    /** A workspace whose plan was cancelled or went unpaid keeps its work but cannot spend credits. */
    public function billingLocked(): bool
    {
        return (bool) $this->billingSubscription()?->isLocked();
    }

    /** Credits generation may use now: none while billing is locked. */
    public function spendableCredits(): int
    {
        return $this->billingLocked() ? 0 : $this->creditBalance();
    }

    public function creditShortfallMessage(string $default): string
    {
        return $this->billingLocked() ? self::BILLING_LOCKED_MESSAGE : $default;
    }
}
