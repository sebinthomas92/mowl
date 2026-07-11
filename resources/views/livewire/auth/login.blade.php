<main class="auth-shell">
    <section class="auth-brand-panel">
        <div class="auth-lockup"><img src="/marketing-owl-logo.png" alt=""><span>Marketing<br>Owl</span></div>
        <div class="auth-promise"><p>AGENCY CAMPAIGN INTELLIGENCE</p><h1>Turn product truth into campaigns your buyers can trust.</h1><span>Source-linked packs. Structured evidence. Copy ready to ship.</span></div>
        <div class="auth-standard"><b>50</b><span>pack credits<br>included in beta</span></div>
    </section>
    <section class="auth-form-panel">
        <form wire:submit="login" class="auth-form">
            <p class="kicker">WELCOME BACK</p><h2>Enter the workspace.</h2><p>Continue building approved campaign packs.</p>
            <label>Email address<input wire:model="email" type="email" autocomplete="email" autofocus>@error('email')<small class="error">{{ $message }}</small>@enderror</label>
            <label>Password<input wire:model="password" type="password" autocomplete="current-password">@error('password')<small class="error">{{ $message }}</small>@enderror</label>
            <label class="remember-row"><input wire:model="remember" type="checkbox"> Keep me signed in</label>
            <button class="primary-button" type="submit">Sign in <span>→</span></button>
            <p class="auth-switch">New to Marketing Owl? <a href="{{ route('register') }}">Create your workspace</a></p>
        </form>
    </section>
</main>
