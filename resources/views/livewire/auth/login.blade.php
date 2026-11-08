<main class="auth-shell">
    <section class="auth-brand-panel">
        <div class="auth-lockup"><img src="/marketing-owl-logo.png" alt=""><span>Marketing<br>Owl</span></div>
        <div class="auth-promise"><p>LAUNCH CONTENT FROM YOUR PRODUCT PAGE</p><h1>Turn product truth into campaigns your buyers can trust.</h1><span>For online stores and the agencies that run their marketing. Every claim comes from your product page.</span></div>
        <div class="auth-standard"><b>50</b><span>pack credits<br>included in beta</span></div>
    </section>
    <section class="auth-form-panel">
        <form wire:submit="login" class="auth-form">
            <p class="kicker">WELCOME BACK</p><h2>Enter the workspace.</h2><p>Continue building approved launch packs.</p>
            @if(session('status'))<p class="auth-status" role="status">{{ session('status') }}</p>@endif
            <label>Email address<input wire:model="email" type="email" autocomplete="email" autofocus>@error('email')<small class="error">{{ $message }}</small>@enderror</label>
            <label>Password<input wire:model="password" type="password" autocomplete="current-password">@error('password')<small class="error">{{ $message }}</small>@enderror</label>
            @if(\App\Support\PasswordResets::available())<p class="auth-forgot"><a href="{{ route('password.request') }}" wire:navigate>Forgot password?</a></p>@endif
            <label class="remember-row"><input wire:model="remember" type="checkbox"> Keep me signed in</label>
            <button class="primary-button" type="submit">Sign in <span>→</span></button>
            <p class="auth-switch">New to Marketing Owl? <a href="{{ route('register') }}">Create your workspace</a></p>
        </form>
    </section>
</main>
