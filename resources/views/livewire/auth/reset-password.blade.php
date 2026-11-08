<main class="auth-shell">
    <section class="auth-brand-panel">
        <div class="auth-lockup"><img src="/marketing-owl-logo.png" alt=""><span>Marketing<br>Owl</span></div>
        <div class="auth-promise"><p>LAUNCH CONTENT FROM YOUR PRODUCT PAGE</p><h1>Turn product truth into campaigns your buyers can trust.</h1><span>For online stores and the agencies that run their marketing. Every claim comes from your product page.</span></div>
        <div class="auth-standard"><b>50</b><span>pack credits<br>included in beta</span></div>
    </section>
    <section class="auth-form-panel">
        <form wire:submit="resetPassword" class="auth-form">
            <p class="kicker">PASSWORD HELP</p><h2>Choose a new password.</h2><p>At least 8 characters. You will sign in with it next.</p>
            <label>Email address<input wire:model="email" type="email" autocomplete="email" required>@error('email')<small class="error">{{ $message }}</small>@enderror</label>
            <label>New password<input wire:model="password" type="password" autocomplete="new-password" minlength="8" autofocus required>@error('password')<small class="error">{{ $message }}</small>@enderror</label>
            <label>Confirm new password<input wire:model="password_confirmation" type="password" autocomplete="new-password" minlength="8" required></label>
            <button class="primary-button" type="submit" wire:loading.attr="disabled" wire:target="resetPassword">Save new password <span>→</span></button>
            <p class="auth-switch"><a href="{{ route('login') }}" wire:navigate>Back to sign in</a></p>
        </form>
    </section>
</main>
