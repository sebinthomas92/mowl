<?php

namespace App\Livewire\Auth;

use App\Support\PasswordResets;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Component;

class ForgotPassword extends Component
{
    public string $email = '';

    public bool $sent = false;

    public function sendLink(): void
    {
        abort_unless(PasswordResets::available(), 404);

        $this->validate(['email' => ['required', 'email', 'max:255']]);

        $throttleKey = 'forgot-password:'.request()->ip();
        if (RateLimiter::tooManyAttempts($throttleKey, 5)) {
            $this->addError('email', 'Too many requests. Wait '.RateLimiter::availableIn($throttleKey).' seconds and try again.');

            return;
        }
        RateLimiter::hit($throttleKey, 300);

        // Same answer whether or not the address has an account, so the form can't be used to find accounts.
        Password::sendResetLink(['email' => $this->email]);
        $this->sent = true;
    }

    public function render()
    {
        return view('livewire.auth.forgot-password', ['available' => PasswordResets::available()])
            ->layout('components.layouts.app')->title('Reset your password');
    }
}
