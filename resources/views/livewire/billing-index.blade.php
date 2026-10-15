<div class="app-shell">
    <a class="skip-link" href="#main-content">Skip to billing</a>
    @include('partials.workspace-sidebar')
    <div class="app-main">
        @include('partials.top-bar')
        <main id="main-content" class="lib-page billing-page">
            <div class="pagehead">
                <div>
                    <div class="eyebrow">Workspace</div>
                    <h1 class="display">Billing</h1>
                    <p class="muted">Your plan, launch pack credits and invoices. Payments run through Stripe; card details never touch Marketing Owl.</p>
                </div>
                @if($ready && $testMode)<span class="tag warn"><span class="dot"></span>Stripe test mode</span>@endif
            </div>

            @if($notice)<div class="banner ok small" role="status"><i class="ti ti-circle-check" aria-hidden="true"></i><span>{{ $notice }}</span></div>@endif
            @error('billing')<div class="banner bad small" role="alert"><i class="ti ti-alert-triangle" aria-hidden="true"></i><span>{{ $message }}</span></div>@enderror
            @if($locked)
                <div class="banner bad small" role="status"><i class="ti ti-lock" aria-hidden="true"></i><span>The plan has ended, so launch packs, regenerations and banners are paused. Everything you made stays here. {{ $isOwner ? 'Restart the plan to generate again.' : 'Ask '.($owner?->name ?? 'the owner').' to restart the plan.' }}</span></div>
            @elseif($subscription?->status === 'past_due')
                <div class="banner warn small" role="status"><i class="ti ti-credit-card" aria-hidden="true"></i><span>The last payment failed. Stripe will retry; update the card in the billing portal to avoid the plan ending.</span></div>
            @endif
            @unless($ready)
                <div class="banner warn small" role="status"><i class="ti ti-plug-x" aria-hidden="true"></i><span>Payments are not switched on yet. The plan can be started once Stripe is connected.</span></div>
            @endunless

            <div class="grid">
                <section class="card col c7 billing-plan" aria-labelledby="plan-heading">
                    <div class="row billing-plan-head">
                        <span class="eyebrow" id="plan-heading">Plan</span>
                        @if($subscribed)
                            <span class="tag ok"><span class="dot"></span>{{ $subscription->status === 'trialing' ? 'Trial' : ($subscription->status === 'past_due' ? 'Payment due' : 'Active') }}</span>
                        @elseif($locked)
                            <span class="tag bad"><span class="dot"></span>Ended</span>
                        @else
                            <span class="tag"><span class="dot"></span>Beta credits</span>
                        @endif
                    </div>
                    <h2 class="billing-plan-name">{{ $plan['name'] }}</h2>
                    <p class="billing-price"><strong class="num">${{ number_format($plan['amount'] / 100) }}</strong><span class="muted">/ {{ $plan['interval'] }}</span></p>
                    <ul class="billing-features">
                        <li><i class="ti ti-check" aria-hidden="true"></i>{{ $plan['credits'] }} launch pack credits every month; unused credits carry over</li>
                        <li><i class="ti ti-check" aria-hidden="true"></i>Standard pack 1 credit, deep pack 3, with 3 free section regenerations in the first day</li>
                        <li><i class="ti ti-check" aria-hidden="true"></i>{{ config('campaigns.seat_limit') }} seats and {{ config('campaigns.brand_limit') }} brands; clients are free</li>
                        <li><i class="ti ti-check" aria-hidden="true"></i>Extra credits at $2 each, bought up front, never billed by surprise</li>
                    </ul>
                    @if($subscription?->current_period_ends_at && ($subscribed || $locked))
                        <p class="small muted">
                            @if($locked) Ended {{ ($subscription->canceled_at ?? $subscription->current_period_ends_at)->format('M j, Y') }}.
                            @elseif($subscription->cancel_at_period_end) Cancels on {{ $subscription->current_period_ends_at->format('M j, Y') }}. Credits stay usable until then.
                            @else Renews {{ $subscription->current_period_ends_at->format('M j, Y') }} with {{ $plan['credits'] }} new credits.
                            @endif
                        </p>
                    @endif
                    @if($isOwner)
                        <div class="row">
                            @if($subscribed)
                                <button type="button" class="pill dark sm" wire:click="openPortal" wire:loading.attr="disabled" @disabled(! $ready)>Manage billing and invoices</button>
                            @else
                                <button type="button" class="pill pri sm" wire:click="startPlan" wire:loading.attr="disabled" @disabled(! $ready)>{{ $locked ? 'Restart the plan' : 'Start the plan' }}</button>
                                @if($subscription?->stripe_customer_id)<button type="button" class="pill ghost sm" wire:click="openPortal" wire:loading.attr="disabled" @disabled(! $ready)>Past invoices</button>@endif
                            @endif
                        </div>
                    @else
                        <p class="small muted">Only {{ $owner?->name ?? 'the workspace owner' }} can change the plan or payment details.</p>
                    @endif
                </section>

                <section class="card col c5 billing-credits" aria-labelledby="credits-heading">
                    <span class="eyebrow" id="credits-heading">Credits</span>
                    <p class="billing-balance"><strong class="num">{{ $creditBalance }}</strong><span class="muted">available</span></p>
                    <div class="billing-packs">
                        @foreach($packs as $key => $pack)
                            <div class="billing-pack" wire:key="pack-{{ $key }}">
                                <div><strong class="medium">{{ $pack['credits'] }} credits</strong><span class="muted xs">${{ number_format($pack['amount'] / 100) }} · one-time</span></div>
                                @if($isOwner)
                                    <button type="button" class="pill ghost sm" wire:click="buyCredits('{{ $key }}')" wire:loading.attr="disabled" @disabled(! $ready || ! $subscribed)>Buy</button>
                                @endif
                            </div>
                        @endforeach
                    </div>
                    <p class="small muted">{{ $subscribed ? 'Extra credits never expire and pool with your monthly credits.' : 'Extra credits are available once the plan is active.' }}</p>
                </section>

                <section class="col c12" aria-labelledby="history-heading">
                    <span class="eyebrow" id="history-heading">Credit grants</span>
                    <div class="tw">
                        <table class="tbl billing-history">
                            <thead><tr><th scope="col">Date</th><th scope="col">What</th><th scope="col">Credits</th></tr></thead>
                            <tbody>
                                @forelse($billingEvents as $event)
                                    <tr wire:key="credit-{{ $event->id }}"><td class="num">{{ $event->created_at->format('M j, Y') }}</td><td>{{ $event->description }}</td><td class="num">{{ $event->amount > 0 ? '+' : '' }}{{ $event->amount }}</td></tr>
                                @empty
                                    <tr><td colspan="3" class="muted">No credits granted yet.</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                    <p class="small muted">Spending per launch pack is on the <a href="{{ route('usage.index') }}" wire:navigate>Usage</a> page. Invoices and receipts are in the billing portal.</p>
                </section>
            </div>
        </main>
    </div>
</div>
