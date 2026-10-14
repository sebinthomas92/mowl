@php
    $heroImage = $product->mediaAssets->first(fn ($asset) => str_starts_with((string) $asset->mime_type, 'image/') && $asset->status !== 'failed');
    $needsDecision = $facts->whereIn('status', ['conflict', 'missing'])->count();
    $inReview = $packs->where('status', 'review')->count();
    $checkedAt = $source?->fetched_at ?? $source?->created_at;
    $stale = $checkedAt && $checkedAt->lt(now()->subDays(14));
    $siblings = \App\Models\Product::query()
        ->whereHas('brand', fn ($query) => $query->where('workspace_id', $workspace->id))
        ->with('brand')->orderBy('name')->limit(30)->get();
@endphp
<section class="hero">
    <div class="hero-bg" aria-hidden="true"><span class="blob blob-a"></span><span class="blob blob-b"></span></div>
    <div class="crumb"><a href="{{ route('products.index') }}" wire:navigate>Products</a> / {{ $product->brand->name }} / <span>{{ $product->name }}</span></div>
    <div class="hero-row">
        <div class="pimg">
            @if($heroImage)<img src="{{ route('products.hub-media', [$product, $heroImage]) }}" alt="{{ $product->name }}">@endif
        </div>
        <div class="hero-title">
            <div class="hero-name">
                <h1 class="display">{{ $product->name }}</h1>
                @if($siblings->count() > 1)
                    <details class="product-switch">
                        <summary class="iconbtn" aria-label="Switch product"><i class="ti ti-chevron-down" aria-hidden="true"></i></summary>
                        <div class="menu" role="menu">
                            <div class="mh">Switch product</div>
                            @foreach($siblings as $option)
                                <a href="{{ route('products.show', [$option, 'tab' => $tab]) }}" wire:navigate role="menuitem" @if($option->is($product)) aria-current="page" @endif>
                                    <i @class(['ti', 'ti-check' => $option->is($product), 'ti-point' => ! $option->is($product)]) aria-hidden="true"></i>
                                    <span class="grow">{{ $option->name }}<br><span class="xs muted">{{ $option->brand->name }}</span></span>
                                </a>
                            @endforeach
                        </div>
                    </details>
                @endif
            </div>
            <div class="tags">
                @if($checkedAt)
                    <span @class(['tag', 'warn' => $stale, 'ok' => ! $stale])><span class="dot"></span>Source checked {{ $checkedAt->diffForHumans() }}</span>
                @else
                    <span class="tag"><span class="dot"></span>No source page</span>
                @endif
                @if($needsDecision)<a class="tag warn" href="{{ route('products.show', [$product, 'tab' => 'knowledge']) }}" wire:navigate><span class="dot"></span>{{ trans_choice(':count fact needs confirmation|:count facts need confirmation', $needsDecision) }}</a>@endif
                @if($inReview)<span class="tag rev"><span class="dot"></span>{{ $inReview }} awaiting review</span>@endif
                @if($version)<span class="tag ok"><span class="dot"></span>Approved v{{ $version->version }}</span>@endif
            </div>
        </div>
        <div class="hero-actions">
            @if($source && $canManage && $knowledgeReady)
                <button type="button" class="pill glass" wire:click="refreshSource" wire:loading.attr="disabled" wire:target="refreshSource">
                    <i class="ti ti-refresh" aria-hidden="true" wire:loading.class="spin" wire:target="refreshSource"></i>
                    <span wire:loading.remove wire:target="refreshSource">Refresh source</span><span wire:loading wire:target="refreshSource">Checking…</span>
                </button>
            @endif
            <a class="pill pri" href="{{ route('campaign-packs.create') }}" wire:navigate>Create <i class="ti ti-arrow-right" aria-hidden="true"></i></a>
        </div>
    </div>
</section>
