<?php

namespace App\Livewire;

use App\Concerns\InteractsWithWorkspace;
use App\Mail\WorkspaceInvitationMail;
use App\Models\WorkspaceInvitation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Livewire\Component;

class TeamIndex extends Component
{
    use InteractsWithWorkspace;

    public string $email = '';

    public ?string $inviteUrl = null;

    public function invite(): void
    {
        $workspace = $this->currentWorkspace();
        $this->authorizeOwner($workspace->id);

        $data = $this->validate(['email' => ['required', 'email', 'max:255']]);
        $email = Str::lower($data['email']);

        if ($workspace->users()->whereRaw('LOWER(email) = ?', [$email])->exists()) {
            $this->addError('email', 'This person is already a workspace member.');

            return;
        }

        $activeInvites = $workspace->invitations()->whereNull('accepted_at')->where('expires_at', '>', now())->count();
        if ($workspace->users()->count() + $activeInvites >= config('campaigns.seat_limit')) {
            $this->addError('email', 'All five beta seats are already assigned or reserved.');

            return;
        }

        $token = Str::random(48);
        $invitation = DB::transaction(function () use ($workspace, $email, $token): WorkspaceInvitation {
            $workspace->invitations()
                ->whereRaw('LOWER(email) = ?', [$email])
                ->whereNull('accepted_at')
                ->delete();

            return $workspace->invitations()->create([
                'invited_by_user_id' => auth()->id(),
                'email' => $email,
                'token_hash' => hash('sha256', $token),
                'role' => 'member',
                'expires_at' => now()->addDays(7),
            ]);
        });

        $this->inviteUrl = route('invitations.accept', ['token' => $token]);
        Mail::to($invitation->email)->send(new WorkspaceInvitationMail($invitation, $this->inviteUrl));
        $this->email = '';
        session()->flash('status', 'Invite emailed to '.$invitation->email.'.');
    }

    public function revoke(int $invitationId): void
    {
        $workspace = $this->currentWorkspace();
        $this->authorizeOwner($workspace->id);
        $workspace->invitations()->whereKey($invitationId)->whereNull('accepted_at')->delete();
        $this->inviteUrl = null;
        session()->flash('status', 'Invitation revoked.');
    }

    public function removeMember(int $userId): void
    {
        $workspace = $this->currentWorkspace();
        $this->authorizeOwner($workspace->id);

        $member = $workspace->users()->whereKey($userId)->firstOrFail();
        abort_if($member->pivot->role === 'owner', 422);
        $workspace->users()->detach($member);
        session()->flash('status', $member->name.' was removed from the workspace.');
    }

    private function authorizeOwner(int $workspaceId): void
    {
        abort_unless(
            auth()->user()->workspaces()->whereKey($workspaceId)->wherePivot('role', 'owner')->exists(),
            403,
        );
    }

    public function render()
    {
        $workspace = $this->currentWorkspace();

        return view('livewire.team-index', [
            'workspace' => $workspace,
            'members' => $workspace->users()->orderByPivot('created_at')->get(),
            'invitations' => $workspace->invitations()
                ->whereNull('accepted_at')
                ->where('expires_at', '>', now())
                ->latest()
                ->get(),
            'isOwner' => auth()->user()->workspaces()->whereKey($workspace->id)->firstOrFail()->pivot->role === 'owner',
            'seatLimit' => config('campaigns.seat_limit'),
        ])->layout('components.layouts.app');
    }
}
