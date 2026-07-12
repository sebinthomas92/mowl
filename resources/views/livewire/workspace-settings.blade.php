<div class="app-shell" x-data="{ mobileNav: false }">
    <a class="skip-link" href="#main-content">Skip to workspace settings</a>
    @include('partials.workspace-sidebar')
    <main class="main-panel" id="main-content">
        <header class="mobile-header"><button type="button" @click="mobileNav = !mobileNav" :aria-expanded="mobileNav.toString()" aria-label="Toggle navigation">☰</button><div><img src="/marketing-owl-logo.png" alt=""> Marketing Owl</div><span>Beta</span></header>
        <section class="library-page settings-page">
            <header class="library-header"><div><p class="kicker">WORKSPACE CONTROL</p><h1>Settings</h1><p>Keep ownership, plan limits, and operating context clear for the people building campaigns.</p></div></header>

            @if(session('status'))<div class="team-notice" role="status">✓ {{ session('status') }}</div>@endif

            <div class="settings-layout">
                <section class="settings-card" aria-labelledby="workspace-name-heading">
                    <p class="kicker">IDENTITY</p><h2 id="workspace-name-heading">Workspace details</h2>
                    @if($isOwner)
                        <form wire:submit="save">
                            <label>Workspace name<input wire:model="name" type="text" maxlength="120" autocomplete="organization"></label>
                            @error('name')<small class="error">{{ $message }}</small>@enderror
                            <button class="primary-button" type="submit">Save workspace name <span>→</span></button>
                        </form>
                    @else
                        <div class="owner-only"><strong>Owner action</strong><p>Only {{ $owner?->name ?? 'the workspace owner' }} can change the workspace name.</p></div>
                    @endif
                </section>

                <section class="settings-card settings-summary" aria-labelledby="workspace-summary-heading">
                    <p class="kicker">ACCOUNT SUMMARY</p><h2 id="workspace-summary-heading">Beta workspace</h2>
                    <dl>
                        <div><dt>Owner</dt><dd>{{ $owner?->name ?? 'Unassigned' }}<small>{{ $owner?->email }}</small></dd></div>
                        <div><dt>Members</dt><dd>{{ $memberCount }} of {{ config('campaigns.seat_limit') }} seats</dd></div>
                        <div><dt>Brands</dt><dd>{{ $brandCount }} of {{ config('campaigns.brand_limit') }} brands</dd></div>
                        <div><dt>Credits</dt><dd>{{ $creditBalance }} available</dd></div>
                        <div><dt>Billing</dt><dd>{{ $billingConnected ? 'Connected' : 'Concierge setup pending' }}<small>Beta is $129/month · $2/overage credit</small></dd></div>
                    </dl>
                    <div class="settings-members"><span>MEMBERS</span>@foreach($members as $member)<p><strong>{{ $member->name }}</strong><small>{{ strtoupper($member->pivot->role) }}</small></p>@endforeach</div>
                </section>
            </div>
        </section>
    </main>
</div>
