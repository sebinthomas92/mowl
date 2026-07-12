<div class="app-shell" x-data="{ mobileNav: false }">
    <a class="skip-link" href="#main-content">Skip to usage</a>
    @include('partials.workspace-sidebar')
    <main class="main-panel" id="main-content">
        <header class="mobile-header"><button type="button" @click="mobileNav = !mobileNav" :aria-expanded="mobileNav.toString()" aria-label="Toggle navigation">☰</button><div><img src="/marketing-owl-logo.png" alt=""> Marketing Owl</div><span>Beta</span></header>
        <section class="library-page usage-page">
            <header class="library-header"><div><p class="kicker">ACCOUNT CONTROL</p><h1>Usage & cost</h1><p>Pack credits, provider spend, and cost exceptions—visible before they become surprises.</p></div><div class="cost-standard"><span>STANDARD PACK TARGET</span><strong>≤ ${{ number_format($cogsTarget, 2) }}</strong><small>Alert at ${{ number_format($cogsAlert, 2) }}</small></div></header>

            <div class="usage-metrics">
                <article><span>PACK CREDITS</span><strong>{{ $creditBalance }}</strong><small>{{ $creditsSpent }} consumed this allocation</small></article>
                <article><span>COMPLETED JOBS</span><strong>{{ $completedJobs }}</strong><small>Initial and section generations</small></article>
                <article><span>TRACKED COGS</span><strong>${{ number_format($totalCost, 3) }}</strong><small>Provider-estimated total</small></article>
                <article @class(['alert-metric' => $costAlerts > 0])><span>COST ALERTS</span><strong>{{ $costAlerts }}</strong><small>{{ $costAlerts ? 'Review jobs over $'.number_format($cogsAlert, 2) : 'No exceptions detected' }}</small></article>
            </div>

            <div class="usage-layout">
                <section class="usage-ledger" aria-labelledby="jobs-heading">
                    <div class="team-section-heading"><div><p class="kicker">PROVIDER LEDGER</p><h2 id="jobs-heading">Generation jobs</h2></div><span>{{ $jobs->count() }} recent</span></div>
                    @if($jobs->isEmpty())
                        <div class="usage-empty"><span>⌁</span><h3>No generation spend yet</h3><p>The first campaign pack will create a provider and credit record here.</p></div>
                    @else
                        <div class="job-ledger-heading"><span>Pack / operation</span><span>Status</span><span>Usage</span><span>Cost</span></div>
                        @foreach($jobs as $job)
                            <article class="job-ledger-row">
                                <div><strong>{{ $job->campaignPack?->product?->name ?? 'Campaign pack' }}</strong><small>{{ $job->section ? 'Regenerate · '.str_replace('_', ' ', $job->section) : ucfirst($job->analysis_mode).' analysis' }} · {{ $job->created_at->format('M j, H:i') }}</small></div>
                                <span @class(['ledger-status', 'failed' => $job->status === 'failed'])>{{ strtoupper($job->status) }}</span>
                                <span>{{ number_format(($job->input_tokens + $job->output_tokens) / 1000, 1) }}K <small>tokens</small></span>
                                <strong @class(['ledger-cost', 'alert' => $job->cost_alert])>${{ number_format((float) $job->estimated_cost, 3) }} @if($job->cost_alert)<i>!</i>@endif</strong>
                            </article>
                        @endforeach
                    @endif
                </section>

                <section class="credit-ledger" aria-labelledby="credits-heading">
                    <div class="team-section-heading"><div><p class="kicker">CREDIT LEDGER</p><h2 id="credits-heading">Pack credits</h2></div></div>
                    <div class="credit-balance"><span>AVAILABLE</span><strong>{{ $creditBalance }}</strong><small>of {{ config('campaigns.monthly_credits') }} beta credits</small><i style="width:{{ min(100, ($creditBalance / max(1, config('campaigns.monthly_credits'))) * 100) }}%"></i></div>
                    <div class="credit-events">
                        @forelse($creditEvents as $event)
                            <article><span @class(['positive' => $event->amount > 0])>{{ $event->amount > 0 ? '+' : '' }}{{ $event->amount }}</span><div><strong>{{ $event->description }}</strong><small>{{ $event->created_at->diffForHumans() }}</small></div></article>
                        @empty
                            <p>No credit events recorded.</p>
                        @endforelse
                    </div>
                    <div class="overage-note"><span>$2</span><p><strong>Per extra pack credit</strong>Overage is explicit; there is no unlimited plan.</p></div>
                </section>
            </div>
        </section>
    </main>
</div>
