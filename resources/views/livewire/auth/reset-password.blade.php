<main class="auth-shell">
    <section class="auth-brand-panel">
        <div class="auth-lockup"><img src="/marketing-owl-logo.png" alt=""><span>Marketing<br>Owl</span></div>
        <div class="auth-promise"><p>ACCOUNT RECOVERY</p><h1>Secure your workspace access.</h1><span>Choose a unique password with at least eight characters.</span></div>
    </section>
    <section class="auth-form-panel">
        <form wire:submit="resetPassword" class="auth-form">
            <p class="kicker">RESET PASSWORD</p><h2>Choose a new password.</h2><p>This will replace your current Marketing Owl password.</p>
            <label>Email address<input wire:model="email" type="email" autocomplete="email" autofocus>@error('email')<small class="error">{{ $message }}</small>@enderror</label>
            <label>New password<input wire:model="password" type="password" autocomplete="new-password">@error('password')<small class="error">{{ $message }}</small>@enderror</label>
            <label>Confirm password<input wire:model="password_confirmation" type="password" autocomplete="new-password"></label>
            <button class="primary-button" type="submit">Reset password <span>→</span></button>
            <p class="auth-switch"><a href="{{ route('login') }}">Return to sign in</a></p>
        </form>
    </section>
</main>
