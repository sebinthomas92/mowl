<?php

namespace App\Livewire\Auth;

use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

class Login extends Component
{
    public string $email = '';

    public string $password = '';

    public bool $remember = false;

    public function login(): void
    {
        $credentials = $this->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        if (! Auth::attempt($credentials, $this->remember)) {
            throw ValidationException::withMessages(['email' => 'These credentials do not match our records.']);
        }

        request()->session()->regenerate();
        $this->redirectIntended(route('campaign-packs.index'), navigate: true);
    }

    public function render()
    {
        return view('livewire.auth.login')->layout('components.layouts.app');
    }
}
