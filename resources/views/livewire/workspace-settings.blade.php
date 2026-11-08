<div class="app-shell">
    <a class="skip-link" href="#main-content">Skip to workspace settings</a>
    @include('partials.workspace-sidebar')
    <div class="app-main">
        @include('partials.top-bar')
        <main id="main-content" class="lib-page settings-page">
            <div class="pagehead">
                <div>
                    <div class="eyebrow">Workspace</div>
                    <h1 class="display">Settings</h1>
                    <p class="muted">The workspace name, what you use Marketing Owl for, who is in it, and the stores and tools it connects to.</p>
                </div>
            </div>

            @if(session('status'))<div class="banner ok small" role="status"><i class="ti ti-circle-check" aria-hidden="true"></i><span>{{ session('status') }}</span></div>@endif

            <div class="grid">
                <section class="card col c7" aria-labelledby="workspace-name-heading">
                    <span class="eyebrow" id="workspace-name-heading">Workspace details</span>
                    @if($isOwner)
                        <form class="col settings-form" wire:submit="save">
                            <div class="field">
                                <label for="ws-name">Workspace name</label>
                                <input id="ws-name" class="input" wire:model="name" type="text" maxlength="120" autocomplete="organization" @error('name') aria-invalid="true" aria-describedby="ws-name-error" @enderror>
                                @error('name')<span class="field-error" id="ws-name-error" role="alert">{{ $message }}</span>@enderror
                            </div>
                            @if($kindAvailable)
                                <fieldset class="settings-kind">
                                    <legend class="field-label">What you use Marketing Owl for</legend>
                                    <div class="opt-grid">
                                        @foreach(\App\Models\Workspace::KINDS as $value => $label)
                                            <label class="opt"><input type="radio" wire:model="kind" value="{{ $value }}" name="kind"><span><strong class="medium">{{ $label }}</strong><br><span class="muted">{{ $value === 'store' ? 'No client features. Share links go to teammates or freelancers.' : 'Adds the client hub, client accounts and the client portal.' }}</span></span></label>
                                        @endforeach
                                    </div>
                                    @error('kind')<span class="field-error" role="alert">{{ $message }}</span>@enderror
                                </fieldset>
                            @endif
                            <div class="row"><button class="pill pri sm" type="submit" wire:loading.attr="disabled" wire:target="save">Save workspace</button></div>
                        </form>
                    @else
                        <div class="kv"><span>Name</span><span>{{ $workspace->name }}</span></div>
                        <div class="banner neu small"><i class="ti ti-lock" aria-hidden="true"></i><span>Only {{ $owner?->name ?? 'the workspace owner' }} can change workspace settings.</span></div>
                    @endif
                </section>

                <section class="card col c5" aria-labelledby="workspace-summary-heading">
                    <span class="eyebrow" id="workspace-summary-heading">Account</span>
                    <div>
                        <div class="kv"><span>Owner</span><span>{{ $owner?->name ?? 'Unassigned' }}@if($owner)<br><span class="muted xs">{{ $owner->email }}</span>@endif</span></div>
                        <div class="kv"><span>Members</span><span class="num">{{ $memberCount }} of {{ config('campaigns.seat_limit') }} seats</span></div>
                        <div class="kv"><span>Brands</span><span class="num">{{ $brandCount }} of {{ config('campaigns.brand_limit') }}</span></div>
                        @if($canSeeCosts)
                            <div class="kv"><span>Credits</span><span class="num">{{ $creditBalance }} available</span></div>
                            <div class="kv"><span>Plan</span><span><a href="{{ route('billing.index') }}" wire:navigate>{{ $billingStatus }}</a><br><span class="muted xs">${{ number_format(config('billing.plan.amount') / 100) }}/month · {{ config('billing.plan.credits') }} credits · $2 per extra credit</span></span></div>
                        @endif
                    </div>
                    <div class="settings-team">
                        <span class="eyebrow">Members</span>
                        @foreach($members as $member)
                            <div class="li" wire:key="member-{{ $member->id }}"><span class="ic">{{ mb_strtoupper(mb_substr($member->name, 0, 1)) }}</span><span class="grow small">{{ $member->name }}</span><span class="tag">{{ \App\Support\Membership::ROLES[$member->pivot->role] ?? ucfirst($member->pivot->role) }}</span></div>
                        @endforeach
                        <a class="pill ghost sm" href="{{ route('team.index') }}" wire:navigate>Manage team</a>
                    </div>
                </section>
            </div>

            @if(\App\Services\Pages\PageBuilder::available())
                <livewire:store-connections />
            @endif

            <section class="card col" aria-labelledby="other-connections-heading">
                <span class="eyebrow" id="other-connections-heading">Other connections</span>
                <div class="li">
                    <span class="ic"><i class="ti ti-file-spreadsheet" aria-hidden="true"></i></span>
                    <span class="grow"><strong class="medium">CSV results import</strong><br><span class="muted xs">Upload exports from any ad or analytics tool on a campaign's Results to measure it against its baseline.</span></span>
                    <span class="tag ok"><span class="dot"></span>Available</span>
                    <a class="pill ghost sm" href="{{ route('campaigns.index') }}" wire:navigate>Open campaigns</a>
                </div>
                <div class="li">
                    <span class="ic"><i class="ti ti-chart-bar" aria-hidden="true"></i></span>
                    <span class="grow"><strong class="medium">Ad platforms</strong><br><span class="muted xs">Spend and return on ad spend straight from Meta and Google Ads. Until then, import their CSV exports.</span></span>
                    <span class="tag"><span class="dot"></span>Planned</span>
                </div>
                <div class="li">
                    <span class="ic"><i class="ti ti-mail" aria-hidden="true"></i></span>
                    <span class="grow"><strong class="medium">Email platform</strong><br><span class="muted xs">Send approved email copy to your email tool. Planned for a later release.</span></span>
                    <span class="tag"><span class="dot"></span>Planned</span>
                </div>
            </section>
        </main>
    </div>
</div>
