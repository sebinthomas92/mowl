<div class="app-shell">
    <a class="skip-link" href="#main-content">Skip to campaigns</a>
    @include('partials.workspace-sidebar')
    <div class="app-main">
        @include('partials.top-bar')
        <main id="main-content" class="lib-page camp-index">
            <div class="pagehead">
                <div>
                    <div class="eyebrow">All products</div>
                    <h1 class="display">Campaigns</h1>
                    <p class="muted">Campaigns live inside each product. This list shows them all in one place, with what each one measured.</p>
                </div>
            </div>
            @if(! $available)
                <div class="empty"><strong>Campaigns turn on after the next database update</strong><span class="small muted">Approved content and pages keep working in the meantime.</span></div>
            @else
                <div class="chips" role="group" aria-label="Status">
                    <button type="button" class="chip" aria-pressed="{{ $status === 'all' ? 'true' : 'false' }}" wire:click="$set('status', 'all')">All <span class="num">{{ array_sum($counts) }}</span></button>
                    @foreach(\App\Models\Campaign::STATUSES as $key => [, $label])
                        <button type="button" class="chip" aria-pressed="{{ $status === $key ? 'true' : 'false' }}" wire:click="$set('status', '{{ $key }}')">{{ $label }} <span class="num">{{ $counts[$key] ?? 0 }}</span></button>
                    @endforeach
                </div>
                @if($campaigns->isEmpty())
                    <div class="empty">
                        <strong>{{ $status === 'all' ? 'No campaigns yet' : 'No '.mb_strtolower(\App\Models\Campaign::STATUSES[$status][1]).' campaigns' }}</strong>
                        <span class="small muted">Plan a campaign from a product's Campaigns tab. It ties an objective and dates to exact approved content.</span>
                        <a class="pill ghost sm" href="{{ route('products.index') }}" wire:navigate>Open products</a>
                    </div>
                @else
                    <div class="tw">
                        <table class="tbl">
                            <thead><tr><th scope="col">Campaign</th><th scope="col">Product</th><th scope="col">Status</th><th scope="col">Dates</th><th scope="col">Results</th><th scope="col"><span class="sr-only">Open</span></th></tr></thead>
                            <tbody>
                                @foreach($campaigns as $campaign)
                                    @php([$tone, $label] = \App\Models\Campaign::STATUSES[$campaign->status])
                                    @php($summary = $summaries[$campaign->id])
                                    <tr wire:key="row-{{ $campaign->id }}">
                                        <td><strong class="medium">{{ $campaign->name }}</strong><br><span class="muted xs">{{ $campaign->objective }}</span></td>
                                        <td>{{ $campaign->product->name }}<br><span class="muted xs">{{ $campaign->product->brand->name }}</span></td>
                                        <td><span class="tag {{ $tone }}"><span class="dot"></span>{{ $label }}</span></td>
                                        <td>{{ $campaign->dateRange() ?? '—' }}</td>
                                        <td>
                                            @if($summary && $summary['conversion'] !== null){{ rtrim(rtrim(number_format($summary['conversion'], 2), '0'), '.') }}% conversion
                                            @elseif($summary && $summary['orders'] !== null){{ number_format($summary['orders']) }} {{ str('order')->plural($summary['orders']) }}
                                            @elseif($summary && $summary['visits'] !== null){{ number_format($summary['visits']) }} visits
                                            @else<span class="muted">None yet</span>@endif
                                        </td>
                                        <td><a class="pill ghost sm" href="{{ route('products.show', [$campaign->product_id, 'tab' => 'campaigns', 'campaign' => $campaign->id]) }}" wire:navigate>Open</a></td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            @endif
        </main>
    </div>
</div>
