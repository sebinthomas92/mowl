<div class="app-shell" x-data="{ mobileNav: false }">
    <a class="skip-link" href="#main-content">Skip to team</a>
    @include('partials.workspace-sidebar')
    <main class="main-panel" id="main-content">
        <header class="mobile-header"><button type="button" @click="mobileNav = !mobileNav" :aria-expanded="mobileNav.toString()" aria-label="Toggle navigation">☰</button><div><img src="/marketing-owl-logo.png" alt=""> Marketing Owl</div><span>Beta</span></header>
        <section class="library-page team-page">
            <header class="library-header"><div><p class="kicker">WORKSPACE ACCESS</p><h1>Team</h1><p>Five deliberate seats. Every member works from the same approved product truth.</p></div><div class="seat-counter"><strong>{{ $members->count() + $invitations->count() }}</strong><span>of {{ $seatLimit }} seats<br>assigned</span></div></header>

            @if(session('status'))<div class="team-notice" role="status">✓ {{ session('status') }}</div>@endif

            <div class="team-layout">
                <section class="team-roster" aria-labelledby="roster-heading">
                    <div class="team-section-heading"><div><p class="kicker">ACTIVE ACCESS</p><h2 id="roster-heading">Workspace members</h2></div><span>{{ $members->count() }} active</span></div>
                    <div class="member-list">
                        @foreach($members as $member)
                            <article class="member-row">
                                <span class="member-avatar">{{ strtoupper(substr($member->name, 0, 1)) }}</span>
                                <div><strong>{{ $member->name }}</strong><small>{{ $member->email }}</small></div>
                                <span class="role-label">{{ strtoupper($member->pivot->role) }}</span>
                                @if($isOwner && $member->pivot->role !== 'owner')<button type="button" wire:click="removeMember({{ $member->id }})" wire:confirm="Remove this member from the workspace?">Remove</button>@endif
                            </article>
                        @endforeach
                    </div>

                    @if($invitations->isNotEmpty())
                        <div class="pending-heading"><span>PENDING INVITATIONS</span><i></i></div>
                        <div class="member-list pending-list">
                            @foreach($invitations as $invitation)
                                <article class="member-row"><span class="member-avatar pending">↗</span><div><strong>{{ $invitation->email }}</strong><small>Expires {{ $invitation->expires_at->diffForHumans() }}</small></div><span class="role-label">MEMBER</span>@if($isOwner)<button type="button" wire:click="revoke({{ $invitation->id }})">Revoke</button>@endif</article>
                            @endforeach
                        </div>
                    @endif
                </section>

                <section class="invite-panel" aria-label="Invite a teammate">
                    <p class="kicker">RESERVE A SEAT</p><h2>Invite a teammate.</h2><p>Create a secure link for an account manager, strategist, or media buyer. It expires in seven days.</p>
                    @if($isOwner)
                        <form wire:submit="invite">
                            <label>Email address<input wire:model="email" type="email" placeholder="buyer@agency.com" autocomplete="email">@error('email')<small class="error">{{ $message }}</small>@enderror</label>
                            <button class="primary-button" type="submit" @disabled($members->count() + $invitations->count() >= $seatLimit)>Create invite link <span>→</span></button>
                        </form>
                        @if($inviteUrl)<div class="invite-link" x-data="{ copied: false }"><span>{{ $inviteUrl }}</span><button type="button" @click="navigator.clipboard.writeText(@js($inviteUrl)); copied = true"><b x-text="copied ? 'Copied' : 'Copy link'"></b></button></div>@endif
                    @else
                        <div class="owner-only"><strong>Owner action</strong><p>Only the workspace owner can reserve or remove seats.</p></div>
                    @endif
                    <div class="seat-policy"><span>01</span><p><strong>Five seats included</strong>No unlimited-seat plan in the beta.</p></div><div class="seat-policy"><span>02</span><p><strong>Member role</strong>Members can build and copy campaign packs.</p></div>
                </section>
            </div>
        </section>
    </main>
</div>
