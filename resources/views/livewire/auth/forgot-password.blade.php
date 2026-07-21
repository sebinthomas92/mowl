<main class="auth-shell">
    <section class="auth-brand-panel">
        <div class="auth-lockup"><img src="/marketing-owl-logo.png" alt=""><span>Marketing<br>Owl</span></div>
        <div class="auth-promise"><p>ACCOUNT RECOVERY</p><h1>Return to your campaign workspace.</h1><span>Reset links expire after 60 minutes.</span></div>
    </section>
    <section class="auth-form-panel">
        <form wire:submit="sendResetLink" class="auth-form">
            <p class="kicker">RESET PASSWORD</p><h2>Recover your account.</h2><p>Enter your account email and we’ll send a secure reset link.</p>
            @if($linkSent)<p>If an account exists for that email, a reset link has been sent.</p>@endif
            <label>Email address<input wire:model="email" type="email" autocomplete="email" autofocus>@error('email')<small class="error">{{ $message }}</small>@enderror</label>
            <button class="primary-button" type="submit">Send reset link <span>→</span></button>
            <p class="auth-switch"><a href="{{ route('login') }}">Return to sign in</a></p>
        </form>
    </section>
</main>
