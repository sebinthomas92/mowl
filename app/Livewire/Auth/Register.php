<?php

namespace App\Livewire\Auth;

use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rules\Password;
use Livewire\Component;

class Register extends Component
{
    public string $name = '';

    public string $workspaceName = '';

    public string $email = '';

    public string $password = '';

    public string $password_confirmation = '';

    public function register(): void
    {
        $data = $this->validate([
            'name' => ['required', 'string', 'max:120'],
            'workspaceName' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'confirmed', Password::min(8)],
        ]);

        $user = DB::transaction(function () use ($data): User {
            $user = User::create([
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => $data['password'],
            ]);
            $workspace = Workspace::create(['name' => $data['workspaceName']]);
            $workspace->users()->attach($user, ['role' => 'owner']);

            return $user;
        });

        Auth::login($user);
        request()->session()->regenerate();
        $this->redirect(route('campaign-packs.index'), navigate: true);
    }

    public function render()
    {
        return view('livewire.auth.register')->layout('components.layouts.app');
    }
}
