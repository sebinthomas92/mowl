<?php

namespace App\Livewire\Auth;

use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceInvitation;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

class Register extends Component
{
    public string $name = '';

    public string $workspaceName = '';

    public string $email = '';

    public string $password = '';

    public string $password_confirmation = '';

    public string $invitationToken = '';

    public ?string $invitedWorkspaceName = null;

    public function mount(): void
    {
        $token = (string) request()->query('invite', '');
        $invitation = $token !== '' ? WorkspaceInvitation::findOpenToken($token) : null;

        if ($invitation) {
            $this->invitationToken = $token;
            $this->invitedWorkspaceName = $invitation->workspace->name;
            $this->email = $invitation->email;
        }
    }

    public function register(): void
    {
        $throttleKey = 'register:'.hash('sha256', (string) request()->ip());

        if (RateLimiter::tooManyAttempts($throttleKey, 5)) {
            $seconds = RateLimiter::availableIn($throttleKey);

            throw ValidationException::withMessages([
                'email' => "Too many registration attempts. Please try again in {$seconds} seconds.",
            ]);
        }

        RateLimiter::hit($throttleKey, 60);
        $invitation = $this->invitationToken !== ''
            ? WorkspaceInvitation::findOpenToken($this->invitationToken)
            : null;

        if ($this->invitationToken !== '' && ! $invitation) {
            $this->addError('email', 'This invitation is no longer valid.');

            return;
        }

        $data = $this->validate([
            'name' => ['required', 'string', 'max:120'],
            'workspaceName' => [$invitation ? 'nullable' : 'required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'confirmed', Password::min(8)],
        ]);

        if ($invitation && strcasecmp($data['email'], $invitation->email) !== 0) {
            $this->addError('email', 'Use the email address this invitation was sent to.');

            return;
        }

        $user = DB::transaction(function () use ($data, $invitation): User {
            $user = User::create([
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => $data['password'],
            ]);
            if ($invitation) {
                $lockedInvitation = WorkspaceInvitation::query()->lockForUpdate()->findOrFail($invitation->id);
                abort_unless($lockedInvitation->isOpen(), 410);
                abort_if($lockedInvitation->workspace->users()->count() >= config('campaigns.seat_limit'), 409);
                $lockedInvitation->workspace->users()->attach($user, ['role' => $lockedInvitation->role]);
                $lockedInvitation->update(['accepted_at' => now()]);
            } else {
                $workspace = Workspace::create(['name' => $data['workspaceName']]);
                $workspace->users()->attach($user, ['role' => 'owner']);
                $workspace->credits()->create([
                    'amount' => config('campaigns.monthly_credits'),
                    'event' => 'beta_allocation',
                    'description' => 'Initial beta pack credits',
                ]);
            }

            return $user;
        });

        Auth::login($user);
        session()->regenerate();
        session(['current_workspace_id' => $invitation?->workspace_id ?? $user->workspaces()->firstOrFail()->id]);
        $this->redirect(route('campaign-packs.index'), navigate: true);
    }

    public function render()
    {
        return view('livewire.auth.register')->layout('components.layouts.app');
    }
}
