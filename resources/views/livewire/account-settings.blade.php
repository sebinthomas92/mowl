{{-- One root element for Livewire: clients get the portal frame, team members the workspace shell. --}}
<div @class(['client-page' => $isClient, 'app-shell' => ! $isClient])>
    <a class="skip-link" href="#main-content">Skip to your account</a>
    @if($isClient)
        <header class="client-head">
            <svg width="30" height="20" viewBox="0 0 30 20" aria-hidden="true"><circle cx="9" cy="10" r="8" fill="#FF5A3D"/><circle cx="21" cy="10" r="8" fill="#EA38D0" opacity=".85"/><circle cx="9" cy="10" r="2.5" fill="#fff"/><circle cx="21" cy="10" r="2.5" fill="#fff"/></svg>
            <div class="grow"><strong class="medium">{{ $workspace->name }} · your account</strong></div>
            <a class="pill ghost sm" href="{{ route('portal.index') }}" wire:navigate><i class="ti ti-arrow-left" aria-hidden="true"></i> Back to your work</a>
            <form method="POST" action="{{ route('logout') }}">@csrf<button type="submit" class="pill ghost sm">Sign out</button></form>
        </header>
        <main class="client-main" id="main-content">
            <section class="library-page settings-page account-page">
                @include('livewire.partials.account-body')
            </section>
        </main>
    @else
        @include('partials.workspace-sidebar')
        <div class="app-main">
            @include('partials.top-bar')
            <main id="main-content">
                <section class="library-page settings-page account-page">
                    @include('livewire.partials.account-body')
                </section>
            </main>
        </div>
    @endif
</div>
