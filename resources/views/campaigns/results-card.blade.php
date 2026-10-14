{{-- One campaign's results: imports, headline numbers, the daily chart and what the numbers cannot tell you. --}}
@php
    $money = function (?float $value, ?string $currency) {
        if ($value === null) { return '—'; }
        $symbol = ['USD' => '$', 'EUR' => '€', 'GBP' => '£', 'INR' => '₹', 'JPY' => '¥'][$currency ?? ''] ?? null;
        $amount = number_format($value, $value >= 1000 || floor($value) == $value ? 0 : 2);
        return $symbol ? $symbol.$amount : trim(($currency ?? '').' '.$amount);
    };
    $rate = fn (?float $value) => $value === null ? '—' : rtrim(rtrim(number_format($value, 2), '0'), '.').'%';
    $isCurrent = $campaign->id === $this->campaignId;
@endphp

@if($campaign->isPlanned())
    <div class="empty"><strong>Results appear after launch</strong><span class="muted small">Launch the campaign, then import a CSV export or pull orders from your store.</span></div>
@else
    @if($summary)
        <div class="row camp-tags">
            <span class="tag">{{ $summary['start']->isSameDay($summary['end']) ? $summary['start']->format('j M') : $summary['start']->format('j M').'–'.$summary['end']->format('j M') }} · {{ $summary['days'] }} {{ str('day')->plural($summary['days']) }}</span>
            @if($summary['currency'])<span class="tag">{{ $summary['currency'] }}</span>@endif
            <span class="tag">Imported {{ $summary['imported_at']?->diffForHumans() }}</span>
            @if($summary['attribution'])<span class="tag">{{ $summary['attribution'] }}</span>@endif
        </div>
        <div class="camp-stats">
            <div><span class="muted xs">Visits</span><span class="display num">{{ $summary['visits'] === null ? '—' : number_format($summary['visits']) }}</span></div>
            <div><span class="muted xs">Conversion</span><span class="display num">{{ $rate($summary['conversion']) }}</span>
                @if($summary['conversion'] !== null && $summary['baseline'] !== null)<span class="muted xs">vs {{ $rate($summary['baseline']) }} baseline</span>@elseif($summary['conversion'] === null)<span class="muted xs">Needs visits and orders</span>@endif
            </div>
            <div><span class="muted xs">Orders</span><span class="display num">{{ $summary['orders'] === null ? '—' : number_format($summary['orders']) }}</span></div>
            <div><span class="muted xs">Revenue</span><span class="display num">{{ $money($summary['revenue'], $summary['currency']) }}</span></div>
        </div>
        @php
            $points = $summary['series']['points'];
            $max = max(1, collect($points)->max('value'));
            $step = 10 ** max(0, floor(log10($max)));
            $top = ceil($max / $step) * $step;
            [$width, $height, $left, $bottom] = [320, 150, 34, 22];
            $barWidth = ($width - $left) / max(1, count($points));
            $labelEvery = max(1, (int) ceil(count($points) / 7));
        @endphp
        @if(count($points) > 1)
            <figure class="camp-chart">
                <svg viewBox="0 0 {{ $width }} {{ $height }}" width="100%" role="img" aria-label="{{ $summary['series']['label'] }}, {{ $summary['start']->format('j M') }} to {{ $summary['end']->format('j M') }}, from {{ number_format($points[0]['value']) }} to {{ number_format(end($points)['value']) }}">
                    @foreach([0, 0.5, 1] as $tick)
                        @php($y = $height - $bottom - $tick * ($height - $bottom - 8))
                        <line x1="{{ $left }}" x2="{{ $width }}" y1="{{ $y }}" y2="{{ $y }}" stroke="var(--line)"/>
                        <text x="{{ $left - 6 }}" y="{{ $y + 3 }}" text-anchor="end" font-size="10" fill="var(--muted)">{{ number_format($top * $tick) }}</text>
                    @endforeach
                    @foreach($points as $index => $point)
                        @php($barHeight = $point['value'] / $top * ($height - $bottom - 8))
                        <rect x="{{ $left + $index * $barWidth + min(6, $barWidth * .2) }}" y="{{ $height - $bottom - $barHeight }}" width="{{ max(1, $barWidth - 2 * min(6, $barWidth * .2)) }}" height="{{ $barHeight }}" rx="{{ $barWidth > 8 ? 3 : 0 }}" fill="var(--accent)" opacity="{{ $loop->last ? 1 : .55 }}"><title>{{ \Carbon\CarbonImmutable::parse($point['date'])->format('j M') }}: {{ number_format($point['value']) }}</title></rect>
                        @if($index % $labelEvery === 0)<text x="{{ $left + $index * $barWidth + $barWidth / 2 }}" y="{{ $height - 6 }}" text-anchor="middle" font-size="10" fill="var(--muted)">{{ \Carbon\CarbonImmutable::parse($point['date'])->format('j M') }}</text>@endif
                    @endforeach
                </svg>
                <figcaption class="muted xs">{{ $summary['series']['label'] }}</figcaption>
            </figure>
        @endif
        <p class="muted xs">Limitations: a {{ $summary['days'] }}-day window, {{ count($summary['sources']) }} {{ str('source')->plural(count($summary['sources'])) }} ({{ implode(' and ', $summary['sources']) }}) and no control group. A change against the baseline is observed; it is not proven to be caused by the creative.@if($summary['mixed']) Visits and orders come from different sources, so they may count differently.@endif</p>
    @else
        <div class="empty"><strong>No results yet</strong><span class="muted small">Import a CSV export from your analytics tool or store, with a Date column and Visits, Orders or Revenue. @if($orderStore)Or pull this product's paid orders from {{ $orderStore->name }}.@endif</span></div>
    @endif

    @if($isCurrent && $this->importing)
        <form class="col camp-import" wire:submit="importCsv" x-data="{ name: '' }">
            <div class="field">
                <label for="csv-file-{{ $campaign->id }}">CSV file</label>
                <input id="csv-file-{{ $campaign->id }}" class="input" type="file" accept=".csv,.tsv,.txt,text/csv"
                       x-on:change="const file = $event.target.files[0]; if (! file) return; if (file.size > 2097152) { alert('The file is larger than 2 MB.'); return; } name = file.name; $wire.set('csvLabel', file.name, false); file.text().then(text => $wire.set('csv', text, false))">
            </div>
            <div class="field">
                <label for="csv-text-{{ $campaign->id }}">Or paste the rows</label>
                <textarea id="csv-text-{{ $campaign->id }}" class="input mono" rows="5" wire:model="csv" placeholder="Date,Visits,Orders,Revenue&#10;2026-10-20,212,4,216.00"></textarea>
                @error('csv')<span class="field-error">{{ $message }}</span>@enderror
            </div>
            <div class="camp-form-row">
                <div class="field"><label for="csv-currency-{{ $campaign->id }}">Currency <span class="muted">(optional)</span></label><input id="csv-currency-{{ $campaign->id }}" class="input input-sm" wire:model="csvCurrency" maxlength="3" placeholder="USD"></div>
                <div class="field"><label for="csv-attr-{{ $campaign->id }}">Attribution <span class="muted">(optional)</span></label><input id="csv-attr-{{ $campaign->id }}" class="input input-sm" wire:model="csvAttribution" maxlength="80" placeholder="Last click"></div>
            </div>
            @error('csvCurrency')<span class="field-error">{{ $message }}</span>@enderror
            <div class="row"><button type="submit" class="pill pri sm" wire:loading.attr="disabled" wire:target="importCsv">Import</button><button type="button" class="pill ghost sm" wire:click="$set('importing', false)">Cancel</button></div>
        </form>
    @else
        <div class="row camp-actions">
            <button type="button" class="pill {{ $summary ? 'ghost' : 'pri' }} sm" wire:click="openImport({{ $campaign->id }})">{{ $summary ? 'Import more' : 'Import CSV' }}</button>
            @if($orderStore)
                <button type="button" class="pill ghost sm" wire:click="pullOrders({{ $campaign->id }})" wire:loading.attr="disabled" wire:target="pullOrders">Pull store orders</button>
            @else
                <button type="button" class="pill ghost sm" disabled title="Connect a WooCommerce store in Settings to pull orders">Pull store orders</button>
            @endif
        </div>
    @endif
    @if($isCurrent)
        @error('store')<span class="field-error" role="alert">{{ $message }}</span>@enderror
    @endif
@endif
