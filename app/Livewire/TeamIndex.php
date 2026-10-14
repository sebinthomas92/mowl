<?php

namespace App\Livewire;

use App\Concerns\InteractsWithWorkspace;
use App\Models\Workspace;
use App\Models\WorkspaceInvitation;
use App\Support\Membership;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Component;

/**
 * Roles decide who can create, review, publish and see costs; a brand scope limits what a member sees.
 * Clients do not take a seat: they only get the client portal for one brand.
 */
class TeamIndex extends Component
{
    use InteractsWithWorkspace;

    public string $email = '';

    public string $role = 'member';

    /** Brand id, or "" for every brand. */
    public string $scope = '';

    public ?string $inviteUrl = null;

    /** @var array<int, array{role: string, scope: string}> member edits keyed by user id */
    public array $access = [];

    public function invite(): void
    {
        $workspace = $this->currentWorkspace();
        $this->authorizeOwner($workspace);

        $data = $this->validate([
            'email' => ['required', 'email', 'max:255'],
            'role' => ['required', Rule::in($this->assignableRoles())],
            'scope' => ['nullable', Rule::in($this->scopeOptions($workspace))],
        ]);
        $email = Str::lower($data['email']);
        $brandId = filled($data['scope'] ?? null) ? (int) $data['scope'] : null;
        if ($data['role'] === 'client' && ! $brandId) {
            $this->addError('scope', 'Choose the brand this client reviews.');

            return;
        }

        if ($workspace->users()->whereRaw('LOWER(email) = ?', [$email])->exists()) {
            $this->addError('email', 'This person is already a workspace member.');

            return;
        }

        if ($data['role'] !== 'client' && self::seatsTaken($workspace) >= config('campaigns.seat_limit')) {
            $this->addError('email', 'All five beta seats are already assigned or reserved.');

            return;
        }

        $token = Str::random(48);
        $invitation = DB::transaction(function () use ($workspace, $email, $token, $data, $brandId): WorkspaceInvitation {
            $workspace->invitations()
                ->whereRaw('LOWER(email) = ?', [$email])
                ->whereNull('accepted_at')
                ->delete();

            return $workspace->invitations()->create(array_filter([
                'invited_by_user_id' => auth()->id(),
                'email' => $email,
                'token_hash' => hash('sha256', $token),
                'role' => $data['role'],
                'brand_id' => $brandId,
                'expires_at' => now()->addDays(7),
            ], fn ($value) => $value !== null));
        });

        $this->inviteUrl = route('invitations.accept', ['token' => $token]);
        $this->reset('email');
        session()->flash('status', 'Invitation created for '.$invitation->email.' as '.Membership::label($invitation->role).'. Copy the link and send it to them.');
    }

    public function revoke(int $invitationId): void
    {
        $workspace = $this->currentWorkspace();
        $this->authorizeOwner($workspace);
        $workspace->invitations()->whereKey($invitationId)->whereNull('accepted_at')->delete();
        $this->inviteUrl = null;
        session()->flash('status', 'Invitation revoked.');
    }

    public function updateAccess(int $userId): void
    {
        $workspace = $this->currentWorkspace();
        $this->authorizeOwner($workspace);
        $member = $workspace->users()->whereKey($userId)->first();
        abort_unless($member, 404);
        abort_if($member->pivot->role === 'owner', 422);

        $this->validate([
            "access.{$userId}.role" => ['required', Rule::in($this->assignableRoles())],
            "access.{$userId}.scope" => ['nullable', Rule::in($this->scopeOptions($workspace))],
        ]);
        $role = $this->access[$userId]['role'];
        $brandId = filled($this->access[$userId]['scope'] ?? null) ? (int) $this->access[$userId]['scope'] : null;
        if ($role === 'client' && ! $brandId) {
            $this->addError("access.{$userId}.scope", 'A client needs a brand.');

            return;
        }
        if ($member->pivot->role === 'client' && $role !== 'client' && self::seatsTaken($workspace) >= config('campaigns.seat_limit')) {
            $this->addError("access.{$userId}.role", 'Every seat is taken. Free one before giving this client a team role.');

            return;
        }

        $pivot = ['role' => $role];
        if (Membership::scopesAvailable()) {
            $pivot['brand_id'] = $brandId;
        }
        $workspace->users()->updateExistingPivot($member->id, $pivot);
        // A narrower scope takes effect in the inbox too: earlier notifications may name other brands' work.
        $narrowed = ($role === 'client' && $member->pivot->role !== 'client')
            || ($brandId !== null && $brandId !== (isset($member->pivot->brand_id) ? (int) $member->pivot->brand_id : null));
        if ($narrowed && Schema::hasTable('notifications')) {
            $member->notifications()->where('workspace_id', $workspace->id)->delete();
        }
        $workspace->auditEvents()->create([
            'actor_user_id' => auth()->id(), 'event' => 'member_access_changed', 'subject_type' => $member::class, 'subject_id' => $member->id,
            'metadata' => ['from' => ['role' => $member->pivot->role, 'brand_id' => $member->pivot->brand_id ?? null], 'to' => $pivot],
        ]);
        session()->flash('status', $member->name.' is now '.Membership::label($role).($brandId ? ' for '.$workspace->brands()->find($brandId)?->name : '').'.');
    }

    public function removeMember(int $userId): void
    {
        $workspace = $this->currentWorkspace();
        $this->authorizeOwner($workspace);

        $member = $workspace->users()->whereKey($userId)->first();
        abort_unless($member, 404);
        abort_if($member->pivot->role === 'owner', 422);
        $workspace->users()->detach($member);
        unset($this->access[$userId]);
        session()->flash('status', $member->name.' was removed from the workspace.');
    }

    /** Team members and open team invitations; clients never take a seat. */
    public static function seatsTaken(Workspace $workspace): int
    {
        return $workspace->users()->wherePivot('role', '!=', 'client')->count()
            + $workspace->invitations()->whereNull('accepted_at')->where('expires_at', '>', now())->where('role', '!=', 'client')->count();
    }

    private function authorizeOwner(Workspace $workspace): void
    {
        abort_unless(Membership::of(auth()->user(), $workspace)?->canManageTeam(), 403);
    }

    /** Clients need a brand, so the role waits for the brand scope column in production. */
    private function assignableRoles(): array
    {
        return Membership::scopesAvailable() ? Membership::ASSIGNABLE : ['member', 'reviewer'];
    }

    private function scopeOptions(Workspace $workspace): array
    {
        if (! Membership::scopesAvailable()) {
            return [''];
        }

        return ['', ...$workspace->brands()->pluck('id')->map(fn ($id) => (string) $id)->all()];
    }

    public function render()
    {
        $workspace = $this->currentWorkspace();
        $members = $workspace->users()->orderByPivot('created_at')->get();
        foreach ($members as $member) {
            $this->access[$member->id] ??= ['role' => $member->pivot->role, 'scope' => (string) ($member->pivot->brand_id ?? '')];
        }
        $brands = $workspace->brands()->orderBy('name')->get(['id', 'name']);

        return view('livewire.team-index', [
            'workspace' => $workspace,
            'members' => $members,
            'invitations' => $workspace->invitations()
                ->whereNull('accepted_at')
                ->where('expires_at', '>', now())
                ->latest()
                ->get(),
            'brands' => $brands,
            'brandNames' => $brands->pluck('name', 'id'),
            'isOwner' => (bool) Membership::of(auth()->user(), $workspace)?->canManageTeam(),
            'roles' => array_intersect_key(Membership::ROLES, array_flip($this->assignableRoles())),
            'scopesAvailable' => Membership::scopesAvailable(),
            'seatsTaken' => self::seatsTaken($workspace),
            'seatLimit' => config('campaigns.seat_limit'),
        ])->layout('components.layouts.app');
    }
}
