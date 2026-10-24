@php
    $defs = \App\Services\ProductKnowledge::FACTS;
    $fact = fn (string $key) => $facts->firstWhere('key', $key);
    $counted = $facts->where('status', '!=', 'excluded');
    $confirmed = $counted->where('status', 'ok')->count();
    $knowledgeUrl = route('products.show', [$product, 'tab' => 'knowledge']);
    $attention = collect();
    foreach ($facts->where('status', 'conflict') as $item) {
        $attention->push(['icon' => 'alert-triangle', 'tone' => 'warn', 'text' => "{$defs[$item->key]['label']} changed on the source", 'label' => 'Review', 'url' => $knowledgeUrl]);
    }
    // An audience the brand supplies is not missing.
    foreach ($facts->where('status', 'missing')->reject(fn ($item) => $item->key === 'audience' && $brandAudience) as $item) {
        $attention->push(['icon' => 'circle-dashed', 'tone' => 'neu', 'text' => "{$defs[$item->key]['label']} is missing", 'label' => 'Add', 'url' => $knowledgeUrl]);
    }
    if ($contentItems !== null) {
        foreach ($contentItems->where('status', 'review') as $item) {
            $attention->push(['icon' => 'eye', 'tone' => 'rev', 'text' => "{$item->title} v{$item->current_version} awaits review", 'label' => 'Review', 'url' => route('review.index', ['item' => $item->id])]);
        }
        foreach ($contentItems->where('status', 'changes') as $item) {
            $attention->push(['icon' => 'refresh', 'tone' => 'warn', 'text' => "Changes requested on {$item->title} v{$item->current_version}", 'label' => 'Revise', 'url' => route('products.show', [$product, 'tab' => 'content', 'item' => $item->id])]);
        }
    } else {
        foreach ($packs->where('status', 'review') as $pack) {
            $attention->push(['icon' => 'eye', 'tone' => 'rev', 'text' => "Launch pack v{$pack->current_version} is waiting for review", 'label' => 'Open', 'url' => route('campaign-packs.show', $pack)]);
        }
    }
    if (! $version) {
        $attention->push(['icon' => 'stack-2', 'tone' => 'neu', 'text' => 'No approved launch pack yet, so the client hub is empty', 'label' => 'Create', 'url' => route('campaign-packs.create', ['product' => $product->id])]);
    }
@endphp
<div class="grid">
    <section class="card col c5">
        <div class="row"><span class="eyebrow">What it is</span><span class="sp"></span>@if($knowledgeReady)<a class="small" href="{{ $knowledgeUrl }}" wire:navigate>Edit in Knowledge</a>@endif</div>
        <p class="display lede">{{ $fact('description')?->status === 'excluded' ? '' : ($fact('description')?->confirmed_value ?: $product->summary ?: 'Add a one-line description in Knowledge.') }}</p>
        <div class="facts-mini">
            @foreach(['price' => $product->price, 'audience' => $brandAudience, 'availability' => null] as $key => $fallback)
                @php($item = $fact($key))
                <div><span class="muted small">{{ $defs[$key]['label'] }}</span>
                    @if($item?->status === 'excluded')<span class="muted">Excluded</span>
                    @elseif($item?->confirmed_value ?: $fallback)<strong>{{ $item?->confirmed_value ?: $fallback }}</strong>
                    @else<span class="muted">Not set</span>@endif
                    @if($item?->status === 'conflict')<span class="tag warn"><span class="dot"></span>Source changed</span>@endif
                </div>
            @endforeach
        </div>
    </section>

    <section class="card c4">
        <div class="eyebrow">Needs attention · {{ $attention->count() }}</div>
        @forelse($attention->take(5) as $item)
            <div class="li"><span class="ic {{ $item['tone'] }}"><i class="ti ti-{{ $item['icon'] }}" aria-hidden="true"></i></span><span class="grow small">{{ $item['text'] }}</span><a class="pill ghost sm" href="{{ $item['url'] }}" wire:navigate>{{ $item['label'] }}</a></div>
        @empty
            <div class="li"><span class="ic ok"><i class="ti ti-check" aria-hidden="true"></i></span><span class="small">Nothing needs attention.</span></div>
        @endforelse
    </section>

    <section class="card col c3">
        <span class="eyebrow">Knowledge</span>
        @if($knowledgeReady)
            <div><span class="display num big">{{ $confirmed }}</span><span class="muted"> of {{ $counted->count() }} facts confirmed</span></div>
            <div class="bar" role="img" aria-label="{{ $confirmed }} of {{ $counted->count() }} facts confirmed"><span style="width: {{ $counted->count() ? round($confirmed / $counted->count() * 100) : 0 }}%"></span></div>
            <ul class="fact-checklist">
                @foreach($facts as $item)
                    <li @class(['ok' => $item->status === 'ok', 'warn' => $item->status === 'conflict', 'muted' => in_array($item->status, ['missing', 'excluded'])])>{{ ['ok' => '✓', 'conflict' => '!', 'missing' => '○', 'excluded' => '–'][$item->status] ?? '•' }} {{ $defs[$item->key]['label'] }}</li>
                @endforeach
            </ul>
            <a class="pill ghost sm" href="{{ $knowledgeUrl }}" wire:navigate>Open Knowledge</a>
        @else
            <p class="small muted">Knowledge turns on once the latest database migration has run.</p>
        @endif
    </section>

    <section class="card c6">
        <div class="row"><span class="eyebrow">Launch packs</span><span class="sp"></span><a class="small" href="{{ route('products.show', [$product, 'tab' => 'packs']) }}" wire:navigate>All packs</a></div>
        @forelse($packs->take(4) as $pack)
            @include('product-hubs.pack-row', ['pack' => $pack])
        @empty
            <div class="empty"><strong>No launch packs yet</strong><span class="small muted">A launch pack turns this product's confirmed facts into channel-ready copy.</span>@if($canManage)<a class="pill pri sm" href="{{ route('campaign-packs.create', ['product' => $product->id]) }}" wire:navigate>Create a launch pack</a>@endif</div>
        @endforelse
    </section>

    <section class="card col c6">
        <div class="row"><span class="eyebrow">{{ $agency ? 'Client hub' : 'Ready to use' }}</span><span class="sp"></span><a class="small" href="{{ route('products.show', [$product, 'tab' => 'hub']) }}" wire:navigate>{{ $agency ? 'Open client hub' : 'Open' }}</a></div>
        @if($version)
            <p class="small">Shows approved launch pack v{{ $version->version }}, approved {{ ($version->reviewed_at ?? $version->updated_at)->diffForHumans() }}.</p>
            <div class="kv"><span>{{ $agency ? 'Client link' : 'Share link' }}</span><span>{{ $shareUrl ? 'Active' : 'Not shared' }}</span></div>
            <div class="kv"><span>Resource links</span><span>{{ $product->resourceLinks->count() }}</span></div>
        @else
            <p class="small muted">Fills in automatically when a launch pack is approved. Drafts never appear here.</p>
        @endif
    </section>

    @if($campaigns !== null)
        <section class="card c12">
            <div class="row"><span class="eyebrow">Campaigns</span><span class="sp"></span><a class="small" href="{{ route('products.show', [$product, 'tab' => 'campaigns']) }}" wire:navigate>Campaigns and results</a></div>
            @forelse($campaigns->take(4) as $item)
                @php([$tone, $label] = \App\Models\Campaign::STATUSES[$item->status])
                <div class="li"><span class="tag {{ $tone }}"><span class="dot"></span>{{ $label }}</span><span class="grow"><strong class="medium">{{ $item->name }}</strong>@if($item->dateRange()) <span class="muted">· {{ $item->dateRange() }}</span>@endif</span><span class="muted small">{{ $item->results_count ? 'Results imported' : ($item->status === 'planned' ? 'Not launched' : 'Waiting for results') }}</span><a class="pill ghost sm" href="{{ route('products.show', [$product, 'tab' => 'campaigns', 'campaign' => $item->id]) }}" wire:navigate>Open</a></div>
            @empty
                <div class="li"><span class="muted small grow">No campaigns yet. A campaign references exact approved versions.</span><a class="pill ghost sm" href="{{ route('products.show', [$product, 'tab' => 'campaigns']) }}" wire:navigate>Plan a campaign</a></div>
            @endforelse
        </section>
    @endif
</div>
