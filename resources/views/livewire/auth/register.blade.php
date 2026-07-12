<main class="auth-shell">
    <section class="auth-brand-panel">
        <div class="auth-lockup"><img src="/marketing-owl-logo.png" alt=""><span>Marketing<br>Owl</span></div>
        <div class="auth-promise"><p>PAID CONCIERGE BETA</p><h1>One home for every product truth and campaign pack.</h1><span>Built for ecommerce performance agencies.</span></div>
        <div class="auth-standard"><b>$129</b><span>per month<br>5 seats · 10 brands</span></div>
    </section>
    <section class="auth-form-panel">
        <form wire:submit="register" class="auth-form register-form">
            <p class="kicker">{{ $invitedWorkspaceName ? 'JOIN THE WORKSPACE' : 'CREATE YOUR WORKSPACE' }}</p><h2>{{ $invitedWorkspaceName ? 'Join '.$invitedWorkspaceName.'.' : 'Start with the agency.' }}</h2><p>{{ $invitedWorkspaceName ? 'Your seat is reserved for seven days.' : 'Your first workspace is private to your team.' }}</p>
            @if($invitedWorkspaceName)
                <label>Your name<input wire:model="name" type="text" autocomplete="name">@error('name')<small class="error">{{ $message }}</small>@enderror</label>
            @else
                <div class="field-row"><label>Your name<input wire:model="name" type="text" autocomplete="name">@error('name')<small class="error">{{ $message }}</small>@enderror</label><label>Workspace name<input wire:model="workspaceName" type="text" placeholder="Agency name">@error('workspaceName')<small class="error">{{ $message }}</small>@enderror</label></div>
            @endif
            <label>Email address<input wire:model="email" type="email" autocomplete="email" @readonly($invitedWorkspaceName)>@error('email')<small class="error">{{ $message }}</small>@enderror</label>
            <div class="field-row"><label>Password<input wire:model="password" type="password" autocomplete="new-password">@error('password')<small class="error">{{ $message }}</small>@enderror</label><label>Confirm password<input wire:model="password_confirmation" type="password" autocomplete="new-password"></label></div>
            <button class="primary-button" type="submit">{{ $invitedWorkspaceName ? 'Join workspace' : 'Create workspace' }} <span>→</span></button>
            <p class="auth-switch">Already have an account? <a href="{{ route('login') }}">Sign in</a></p>
        </form>
    </section>
</main>
