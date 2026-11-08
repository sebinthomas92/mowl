<header class="library-header"><div><p class="kicker">YOUR ACCOUNT</p><h1>Account</h1><p>Your sign-in details. Changes here apply to every workspace you belong to.</p></div></header>

@if(session('status'))<div class="team-notice" role="status">✓ {{ session('status') }}</div>@endif

<div class="settings-layout">
    <section class="settings-card" aria-labelledby="password-heading">
        <p class="kicker">SECURITY</p><h2 id="password-heading">Change password</h2>
        <form wire:submit="updatePassword" novalidate>
            <label>Current password<input wire:model="current_password" type="password" autocomplete="current-password" required></label>
            @error('current_password')<small class="error" role="alert">{{ $message }}</small>@enderror
            <label>New password<input wire:model="password" type="password" autocomplete="new-password" minlength="8" required aria-describedby="password-help"></label>
            @error('password')<small class="error" role="alert">{{ $message }}</small>@enderror
            <label>Confirm new password<input wire:model="password_confirmation" type="password" autocomplete="new-password" minlength="8" required></label>
            <p class="account-help" id="password-help">At least 8 characters. Saving signs you out on your other devices.</p>
            <button class="primary-button" type="submit" wire:loading.attr="disabled" wire:target="updatePassword">Change password <span>→</span></button>
        </form>
    </section>

    <section class="settings-card settings-summary" aria-labelledby="profile-heading">
        <p class="kicker">SIGNED IN AS</p><h2 id="profile-heading">{{ $user->name }}</h2>
        <dl>
            <div><dt>Email</dt><dd>{{ $user->email }}</dd></div>
            <div><dt>Workspace</dt><dd>{{ $workspace->name }}@if($roleLabel)<small>{{ $roleLabel }}</small>@endif</dd></div>
        </dl>
    </section>
</div>
