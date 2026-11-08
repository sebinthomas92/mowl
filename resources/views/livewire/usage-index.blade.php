<div class="app-shell">
    <a class="skip-link" href="#main-content">Skip to usage</a>
    @include('partials.workspace-sidebar')
    <div class="app-main">
        @include('partials.top-bar')
        <main id="main-content" class="lib-page usage-page">
            @php
                $usedShare = min(100, (int) round($creditsUsed / max(1, $creditsUsed + $creditBalance) * 100));
                $statusTag = [
                    'completed' => ['ok', 'Succeeded'],
                    'failed' => ['bad', 'Failed'],
                    'partial' => ['bad', 'Partly failed'],
                    'queued' => ['', 'Queued'],
                    'processing' => ['rev', 'Running'],
                    'retrying' => ['rev', 'Retrying'],
                    'cancelled' => ['', 'Cancelled'],
                ];
            @endphp
            <div class="pagehead">
                <div>
                    <div class="eyebrow">{{ $workspace->name }}</div>
                    <h1 class="display">Usage</h1>
                    <p class="muted">What each run was expected to use before it started, what it actually used, and what the AI providers charged.</p>
                </div>
                <a class="pill ghost sm" href="{{ route('billing.index') }}" wire:navigate><i class="ti ti-credit-card" aria-hidden="true"></i> Plan and credits</a>
            </div>

            <div class="grid">
                <section class="card col c6" aria-labelledby="credits-heading">
                    <span class="eyebrow" id="credits-heading">Credits</span>
                    <div class="usage-figure"><span class="display num">{{ $creditBalance }}</span><span class="muted">credits left · {{ $creditsUsed }} used so far, after refunds</span></div>
                    <div class="bar" role="img" aria-label="{{ $usedShare }}% of credits received have been used"><span style="width:{{ $usedShare }}%"></span></div>
                    <div>
                        <div class="kv"><span>Standard launch pack</span><span>1 credit</span></div>
                        <div class="kv"><span>Deep launch pack</span><span>3 credits</span></div>
                        <div class="kv"><span>Section rewrites</span><span>3 free in the first day</span></div>
                    </div>
                    <span class="muted xs">Failed runs return their credits automatically, so "actual" can be lower than the estimate.</span>
                </section>

                <section class="card col c6" aria-labelledby="cost-heading">
                    <span class="eyebrow" id="cost-heading">AI provider cost</span>
                    <div class="usage-figure"><span class="display num">${{ number_format($totalCost, 2) }}</span><span class="muted">across {{ trans_choice(':count finished run|:count finished runs', $completedJobs) }}</span></div>
                    <div>
                        <div class="kv"><span>Target per standard pack</span><span class="num">${{ number_format($cogsTarget, 2) }} or less</span></div>
                        <div class="kv"><span>Alert above</span><span class="num">${{ number_format($cogsAlert, 2) }}</span></div>
                        <div class="kv"><span>Runs over the alert</span><span>@if($costAlerts)<span class="tag warn"><span class="dot"></span>{{ $costAlerts }}</span>@else<span class="tag ok"><span class="dot"></span>None</span>@endif</span></div>
                    </div>
                    <span class="muted xs">Provider cost is what Marketing Owl pays the AI services. It is never shown to clients.</span>
                </section>

                <section class="col c12" aria-labelledby="runs-heading">
                    <div class="row"><h2 class="usage-h2" id="runs-heading">Runs: estimate against actual</h2><span class="sp"></span><span class="muted xs">Latest {{ $runs->count() }}</span></div>
                    @if($runs->isEmpty())
                        <div class="empty"><strong>No runs yet</strong><span class="small muted">Your first launch pack shows here with its estimate, the credits it actually used and the provider cost.</span></div>
                    @else
                        <div class="tw">
                            <table class="tbl usage-runs">
                                <caption class="sr-only">Generation runs with estimated and actual usage</caption>
                                <thead><tr><th scope="col">Run</th><th scope="col">Status</th><th scope="col" class="num-col">Estimate</th><th scope="col" class="num-col">Actual</th><th scope="col" class="num-col">Provider cost</th></tr></thead>
                                <tbody>
                                    @foreach($runs as $run)
                                        @php [$tone, $label] = $statusTag[$run['status']] ?? ['', str($run['status'])->replace('_', ' ')->title()]; @endphp
                                        <tr wire:key="{{ $run['key'] }}" @class(['hl' => $run['alert']])>
                                            <td>
                                                @if($run['pack'])<a class="medium" href="{{ route('campaign-packs.show', $run['pack']) }}" wire:navigate>{{ $run['product'] }}</a>@else<span class="medium">{{ $run['product'] }}</span>@endif
                                                <br><span class="muted xs">{{ $run['what'] }} · {{ $run['at']->format('M j, H:i') }}</span>
                                                @if($run['detail'])<br><span class="xs warn-text">{{ \Illuminate\Support\Str::limit($run['detail'], 140) }}</span>@endif
                                            </td>
                                            <td><span class="tag {{ $tone }}"><span class="dot"></span>{{ $label }}</span></td>
                                            <td class="num num-col">{{ $run['estimate'] ? trans_choice(':count credit|:count credits', $run['estimate']) : 'Included' }}</td>
                                            <td class="num num-col">
                                                @if($run['actual'] === null)<span class="muted">{{ $run['estimate'] ? '—' : 'Included' }}</span>
                                                @else
                                                    <span @class(['ok-text' => $run['actual'] < $run['estimate']])>{{ trans_choice(':count credit|:count credits', $run['actual']) }}</span>
                                                @endif
                                            </td>
                                            <td class="num num-col">${{ number_format($run['cost'], 3) }}@if($run['alert']) <span class="tag warn" title="Over the ${{ number_format($cogsAlert, 2) }} alert">Over alert</span>@endif</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                </section>

                <section class="card col c12" aria-labelledby="history-heading">
                    <span class="eyebrow" id="history-heading">Credit history</span>
                    @forelse($creditEvents as $event)
                        <div class="li" wire:key="credit-{{ $event->id }}">
                            <span @class(['ic', 'ok' => $event->amount > 0])><i class="ti {{ $event->amount > 0 ? 'ti-plus' : 'ti-minus' }}" aria-hidden="true"></i></span>
                            <span class="grow small">{{ $event->description }}<br><span class="muted xs">{{ $event->created_at->diffForHumans() }}</span></span>
                            <span class="num medium">{{ $event->amount > 0 ? '+' : '' }}{{ $event->amount }}</span>
                        </div>
                    @empty
                        <p class="small muted">No credit events yet. Credits you receive and spend are listed here.</p>
                    @endforelse
                </section>
            </div>
        </main>
    </div>
</div>
