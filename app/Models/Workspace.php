<?php

namespace App\Models;

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
        return $this->belongsToMany(User::class, 'workspace_user')->withPivot('role')->withTimestamps();
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
}
