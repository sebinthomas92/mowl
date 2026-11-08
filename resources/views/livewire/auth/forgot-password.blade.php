<main class="auth-shell">
    <section class="auth-brand-panel">
        <div class="auth-lockup"><img src="/marketing-owl-logo.png" alt=""><span>Marketing<br>Owl</span></div>
        <div class="auth-promise"><p>LAUNCH CONTENT FROM YOUR PRODUCT PAGE</p><h1>Turn product truth into campaigns your buyers can trust.</h1><span>For online stores and the agencies that run their marketing. Every claim comes from your product page.</span></div>
        <div class="auth-standard"><b>50</b><span>pack credits<br>included in beta</span></div>
    </section>
    <section class="auth-form-panel">
        @if(! $available)
            <div class="auth-form">
                <p class="kicker">PASSWORD HELP</p><h2>Reset by email is not on yet.</h2><p>Email the Marketing Owl team from the address you signed up with and we will reset it for you.</p>
                <p class="auth-switch"><a href="{{ route('login') }}" wire:navigate>Back to sign in</a></p>
            </div>
        @elseif($sent)
            <div class="auth-form" role="status">
                <p class="kicker">CHECK YOUR EMAIL</p><h2>Reset link on its way.</h2><p>If {{ $email }} has a Marketing Owl account, a reset link is in its inbox. The link works for 60 minutes.</p>
                <p class="auth-switch"><a href="{{ route('login') }}" wire:navigate>Back to sign in</a></p>
            </div>
        @else
            <form wire:submit="sendLink" class="auth-form">
                <p class="kicker">PASSWORD HELP</p><h2>Forgot your password?</h2><p>Enter your email and we will send you a link to choose a new one.</p>
                <label>Email address<input wire:model="email" type="email" autocomplete="email" autofocus required>@error('email')<small class="error">{{ $message }}</small>@enderror</label>
                <button class="primary-button" type="submit" wire:loading.attr="disabled" wire:target="sendLink">Send reset link <span>→</span></button>
                <p class="auth-switch">Remembered it? <a href="{{ route('login') }}" wire:navigate>Back to sign in</a></p>
            </form>
        @endif
    </section>
</main>
