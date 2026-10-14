<?php

namespace App\Support;

use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Facades\Schema;

/**
 * A user's role in one workspace and the brand it is limited to.
 *
 * Owner: everything, including publishing, team and settings.
 * Contributor (stored as "member"): creates products, runs launch packs and submits work for review.
 * Reviewer: sees work in scope and approves or requests changes. Creates nothing and sees no costs.
 * Client: only the client portal for one brand: work in review, approved deliverables and live pages.
 *
 * A brand scope limits every role but the owner to that brand's products, packs and content.
 */
final class Membership
{
    public const ROLES = [
        'owner' => 'Owner',
        'member' => 'Contributor',
        'reviewer' => 'Reviewer',
        'client' => 'Client',
    ];

    /** Roles an owner can give in an invitation or change a member to. */
    public const ASSIGNABLE = ['member', 'reviewer', 'client'];

    public function __construct(
        public readonly Workspace $workspace,
        public readonly User $user,
        public readonly string $role,
        public readonly ?int $brandId,
    ) {}

    /** Production runs migrations as a separate step; brand scopes wait for their column. */
    public static function scopesAvailable(): bool
    {
        $container = app();
        if (! $container->bound('membership.scopes-available')) {
            $container->instance('membership.scopes-available', Schema::hasColumn('workspace_user', 'brand_id') && Schema::hasColumn('workspace_invitations', 'brand_id'));
        }

        return $container->make('membership.scopes-available');
    }

    /** Pivot columns of workspace_user that exist in this database. */
    public static function pivotColumns(): array
    {
        return self::scopesAvailable() ? ['role', 'brand_id'] : ['role'];
    }

    public static function of(?User $user, Workspace|int|null $workspace): ?self
    {
        if (! $user || ! $workspace) {
            return null;
        }
        $id = $workspace instanceof Workspace ? $workspace->id : $workspace;
        $joined = $user->workspaces()->whereKey($id)->first();
        if (! $joined) {
            return null;
        }

        return new self($joined, $user, (string) $joined->pivot->role, isset($joined->pivot->brand_id) ? (int) $joined->pivot->brand_id : null);
    }

    /** The signed-in user's membership in the workspace they are working in. */
    public static function current(): ?self
    {
        $user = auth()->user();
        if (! $user) {
            return null;
        }
        $workspaceId = session('current_workspace_id');
        $membership = $workspaceId ? self::of($user, (int) $workspaceId) : null;
        if (! $membership) {
            $first = $user->workspaces()->first();
            $membership = $first ? self::of($user, $first) : null;
        }

        return $membership;
    }

    public static function label(string $role): string
    {
        return self::ROLES[$role] ?? ucfirst($role);
    }

    public function roleLabel(): string
    {
        return self::label($this->role);
    }

    public function isOwner(): bool
    {
        return $this->role === 'owner';
    }

    public function isClient(): bool
    {
        return $this->role === 'client';
    }

    public function isReviewer(): bool
    {
        return $this->role === 'reviewer';
    }

    /** Create products, run packs, edit drafts and submit them. */
    public function canCreate(): bool
    {
        return in_array($this->role, ['owner', 'member'], true);
    }

    /** Approve or request changes on a version. */
    public function canReview(): bool
    {
        return in_array($this->role, ['owner', 'reviewer'], true);
    }

    public function canPublish(): bool
    {
        return $this->isOwner();
    }

    public function canManageTeam(): bool
    {
        return $this->isOwner();
    }

    /** Generation usage and credits cover every brand, so only unscoped creators see them. */
    public function canSeeCosts(): bool
    {
        return $this->isOwner() || ($this->role === 'member' && $this->brandId === null);
    }

    /** New brands would sit outside a brand scope. */
    public function canCreateBrands(): bool
    {
        return $this->canCreate() && $this->brandId === null;
    }

    public function isScoped(): bool
    {
        return ! $this->isOwner() && ($this->brandId !== null || $this->isClient());
    }

    /** Null means every brand. A client without a brand sees nothing, so a missing scope never widens access. */
    public function brandIds(): ?array
    {
        if ($this->isOwner()) {
            return null;
        }
        if ($this->brandId !== null) {
            return [$this->brandId];
        }

        return $this->isClient() ? [] : null;
    }

    public function allowsBrand(?int $brandId): bool
    {
        $ids = $this->brandIds();

        return $ids === null || in_array((int) $brandId, $ids, true);
    }

    /** Limits a query on a table with a brand_id column (brands use id). */
    public function scopeBrands(Builder $query, string $column = 'brand_id'): Builder
    {
        $ids = $this->brandIds();

        return $ids === null ? $query : $query->whereIn($query->qualifyColumn($column), $ids);
    }

    /** Limits a query on a model with a product relation (packs, content items, pages). */
    public function scopeThroughProduct(Builder $query, string $relation = 'product'): Builder
    {
        $ids = $this->brandIds();

        return $ids === null ? $query : $query->whereHas($relation, fn (Builder $product) => $product->whereIn('brand_id', $ids));
    }

    /**
     * Workspace members who may hear about work on a brand: never clients, and scoped members only for their brand.
     *
     * @return BelongsToMany<User>
     */
    public static function audience(Workspace $workspace, ?int $brandId): BelongsToMany
    {
        $users = $workspace->users()->wherePivot('role', '!=', 'client');
        if (self::scopesAvailable()) {
            $users->where(fn ($query) => $query->whereNull('workspace_user.brand_id')->orWhere('workspace_user.brand_id', $brandId)->orWhere('workspace_user.role', 'owner'));
        }

        return $users;
    }
}
