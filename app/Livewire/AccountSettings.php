<?php

namespace App\Livewire;

use App\Concerns\InteractsWithWorkspace;
use App\Support\Membership;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

/** The signed-in person's own account: name, email and password. Open to every role, clients included. */
class AccountSettings extends Component
{
    use InteractsWithWorkspace;

    public string $current_password = '';

    public string $password = '';

    public string $password_confirmation = '';

    public function updatePassword(): void
    {
        $user = auth()->user();
        $throttleKey = 'change-password:'.$user->id;

        if (RateLimiter::tooManyAttempts($throttleKey, 5)) {
            $seconds = RateLimiter::availableIn($throttleKey);
            throw ValidationException::withMessages([
                'current_password' => "Too many tries. Wait {$seconds} seconds and try again.",
            ]);
        }

        $this->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'string', 'confirmed', Password::min(8), 'different:current_password'],
        ], [
            'password.different' => 'Choose a password that is different from your current one.',
        ]);

        if (! Hash::check($this->current_password, $user->password)) {
            RateLimiter::hit($throttleKey, 60);
            throw ValidationException::withMessages(['current_password' => 'That is not your current password.']);
        }

        RateLimiter::clear($throttleKey);

        $user->forceFill([
            'password' => $this->password,
            'remember_token' => Str::random(60),
        ])->save();

        // Sign out every other browser that was using the old password. This one stays signed in.
        if (config('session.driver') === 'database') {
            DB::table(config('session.table', 'sessions'))
                ->where('user_id', $user->id)
                ->where('id', '!=', session()->getId())
                ->delete();
        }
        // Destroy the old session and rotate its CSRF token, then reload the full
        // page so Livewire, the meta tag and sign-out forms all receive the new token.
        session()->regenerate(true);

        $this->reset('current_password', 'password', 'password_confirmation');
        session()->flash('status', 'Password changed. Other devices have been signed out.');
        $this->redirectRoute('account.edit', navigate: false);
    }

    public function render()
    {
        $workspace = $this->currentWorkspace();
        $membership = Membership::of(auth()->user(), $workspace);

        return view('livewire.account-settings', [
            'workspace' => $workspace,
            'user' => auth()->user(),
            'isClient' => (bool) $membership?->isClient(),
            'roleLabel' => $membership?->roleLabel(),
        ])->layout('components.layouts.app')->title('Your account');
    }
}
