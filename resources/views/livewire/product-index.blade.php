<div class="app-shell">
    <a class="skip-link" href="#main-content">Skip to products</a>
    @include('partials.workspace-sidebar')
    <div class="app-main">
        @include('partials.top-bar')
        <main id="main-content" class="lib-page">
            <div class="pagehead">
                <div>
                    <div class="eyebrow">{{ $workspace->name }}</div>
                    <h1 class="display">Products</h1>
                    <p class="muted">Everything starts with a product. Add one from its web page, confirm its facts, then create launch packs, pages and campaigns from it.</p>
                </div>
                @if($canCreate)<a class="pill pri" href="{{ route('products.create', array_filter(['brand' => $brand])) }}" wire:navigate><i class="ti ti-plus" aria-hidden="true"></i> Add product</a>@endif
            </div>
            @if($notice)<div class="banner ok small" role="status"><i class="ti ti-circle-check" aria-hidden="true"></i><span class="grow">{{ $notice }}</span><button type="button" class="linkbtn" wire:click="$set('notice', null)">Dismiss</button></div>@endif
            @if($active->isNotEmpty() || $filtered || $archivedCount)
                <div class="row product-toolbar">
                    <label class="search product-filter"><i class="ti ti-search" aria-hidden="true"></i><span class="sr-only">Filter products</span><input type="search" wire:model.live.debounce.300ms="q" placeholder="Filter products"></label>
                    <div class="chips" role="group" aria-label="Show">
                        <button type="button" class="chip" aria-pressed="{{ $show === 'active' ? 'true' : 'false' }}" wire:click="$set('show', 'active')">All products</button>
                        <button type="button" class="chip" aria-pressed="{{ $show === 'attention' ? 'true' : 'false' }}" wire:click="$set('show', 'attention')">Needs attention · <span class="num">{{ $attentionCount }}</span></button>
                        @if($archiveAvailable && ($archivedCount || $show === 'archived'))
                            <button type="button" class="chip" aria-pressed="{{ $show === 'archived' ? 'true' : 'false' }}" wire:click="$set('show', 'archived')">Archived · <span class="num">{{ $archivedCount }}</span></button>
                        @endif
                    </div>
                    <div class="chips view-toggle" role="group" aria-label="Layout">
                        <button type="button" class="chip" aria-pressed="{{ $view === 'list' ? 'true' : 'false' }}" wire:click="$set('view', 'list')" aria-label="List"><i class="ti ti-list" aria-hidden="true"></i></button>
                        <button type="button" class="chip" aria-pressed="{{ $view === 'grid' ? 'true' : 'false' }}" wire:click="$set('view', 'grid')" aria-label="Grid"><i class="ti ti-layout-grid" aria-hidden="true"></i></button>
                    </div>
                </div>
            @endif
            @if($brands->count() > 1)
                <div class="chips" role="group" aria-label="Brand">
                    <button type="button" class="chip" aria-pressed="{{ $brand ? 'false' : 'true' }}" wire:click="$set('brand', null)">All brands</button>
                    @foreach($brands as $option)
                        <button type="button" class="chip" aria-pressed="{{ $brand === $option->id ? 'true' : 'false' }}" wire:click="$set('brand', {{ $option->id }})">{{ $option->name }}</button>
                    @endforeach
                </div>
            @endif
            @if($gettingStarted)
                @include('partials.next-steps', ['steps' => $gettingStarted, 'id' => 'start', 'eyebrow' => 'Getting started', 'heading' => $products->isEmpty() ? 'From product page to ready-to-post launch content in four steps.' : 'Keep going: your first launch pack is a few clicks away.'])
            @endif
            @if($products->isEmpty() && $filtered)
                <div class="empty">
                    <strong>{{ $show === 'archived' ? 'No archived products' : ($show === 'attention' && $q === '' ? 'Nothing needs attention' : 'No products match these filters') }}</strong>
                    <span class="small muted">{{ $show === 'attention' && $q === '' ? 'Every product has confirmed facts, a fresh source and no work waiting.' : 'Try another brand or clear the search.' }}</span>
                    <button type="button" class="pill ghost sm" wire:click="clearFilters">Show all products</button>
                </div>
            @elseif($products->isEmpty() && ! $gettingStarted)
                <div class="empty">
                    <strong>{{ $brand ? 'No products for this brand yet' : 'Add your first product' }}</strong>
                    <span class="small muted">Paste the product's web page and we read its name, price and details for you to confirm. Saving a product never starts generation or uses credits.</span>
                    @if($canCreate)<a class="pill pri sm" href="{{ route('products.create', array_filter(['brand' => $brand])) }}" wire:navigate><i class="ti ti-plus" aria-hidden="true"></i> Add product</a>@endif
                </div>
            @elseif($products->isNotEmpty())
                @php
                    $rows = $products->map(function ($product) use ($canCreate, $workspace) {
                        $latestPack = $product->campaignPacks->sortByDesc('id')->first();
                        $hasApproved = $product->campaignPacks->contains(fn ($pack) => $pack->versions->contains('review_status', 'approved'));
                        $show = fn (string $tab = 'overview') => route('products.show', [$product, 'tab' => $tab]);
                        [$tone, $state, $label, $url] = match (true) {
                            $product->isArchived() => ['', 'Archived '.$product->archived_at->diffForHumans(), 'Open', $show()],
                            ($product->conflicts_count ?? 0) > 0 => ['warn', trans_choice(':count fact changed on the product page|:count facts changed on the product page', $product->conflicts_count), 'Confirm facts', $show('knowledge')],
                            ! $latestPack => ['', 'No launch pack yet', $canCreate ? 'Create launch pack' : 'Open', $canCreate ? route('campaign-packs.create', ['product' => $product->id]) : $show()],
                            in_array($latestPack->status, ['queued', 'processing'], true) => ['rev', 'Launch pack is being written', 'Open', route('campaign-packs.show', $latestPack)],
                            $latestPack->status === 'failed' => ['bad', 'Launch pack failed', 'Open', route('campaign-packs.show', $latestPack)],
                            ($product->changes_count ?? 0) > 0 => ['warn', trans_choice(':count output needs changes|:count outputs need changes', $product->changes_count), 'Revise', $show('content')],
                            ($product->in_review_count ?? 0) > 0 => ['rev', trans_choice(':count output waiting for review|:count outputs waiting for review', $product->in_review_count), 'Review', route('review.index')],
                            $hasApproved && $product->hubShares->isEmpty() => ['ok', 'Approved, not shared yet', $workspace->isAgency() ? 'Share with client' : 'Share', $show('hub')],
                            $hasApproved => ['ok', 'Approved and shared', 'Open', $show('hub')],
                            default => ['', 'Launch pack in progress', 'Open', route('campaign-packs.show', $latestPack)],
                        };
                        $checked = $product->source_checked_at ? \Illuminate\Support\Carbon::parse($product->source_checked_at) : null;
                        $source = match (true) {
                            ! $checked => ['', 'Added by hand'],
                            $this->isStale($product) => ['warn', 'Stale · '.$checked->diffForHumans()],
                            default => ['ok', 'Fresh · '.$checked->diffForHumans()],
                        };
                        $image = $product->mediaAssets->first();

                        return compact('product', 'tone', 'state', 'label', 'url', 'source', 'image');
                    });
                @endphp
                @if($view === 'grid')
                    <div class="product-grid">
                        @foreach($rows as $row)
                            @php $product = $row['product']; @endphp
                            <article class="card product-card" wire:key="product-card-{{ $product->id }}">
                                <a class="product-card-img" href="{{ route('products.show', $product) }}" wire:navigate data-initials="{{ mb_strtoupper(mb_substr($product->name, 0, 1)) }}" tabindex="-1" aria-hidden="true">
                                    @if($row['image'])<img src="{{ route('products.hub-media', [$product, $row['image']]) }}" alt="" loading="lazy" onerror="this.remove()">@endif
                                </a>
                                <div><a class="medium" href="{{ route('products.show', $product) }}" wire:navigate>{{ $product->name }}</a><br><span class="muted xs">{{ $product->brand->name }} · {{ $product->price ?: 'Price not set' }}</span></div>
                                <div class="row product-card-tags"><span class="tag {{ $row['source'][0] }}"><span class="dot"></span>{{ $row['source'][1] }}</span><span class="tag {{ $row['tone'] }}"><span class="dot"></span>{{ $row['state'] }}</span></div>
                                <div class="row">
                                    <a class="pill ghost sm" href="{{ $row['url'] }}" wire:navigate>{{ $row['label'] }} <i class="ti ti-arrow-right" aria-hidden="true"></i></a>
                                    <span class="sp"></span>
                                    @include('partials.product-archive-action', ['product' => $product])
                                </div>
                            </article>
                        @endforeach
                    </div>
                @else
                    <div class="tw">
                        <table class="tbl product-table">
                            <thead><tr><th scope="col"><span class="sr-only">Image</span></th><th scope="col">Product</th><th scope="col">Brand</th><th scope="col">Source</th><th scope="col">Where it stands</th><th scope="col">Next step</th></tr></thead>
                            <tbody>
                                @foreach($rows as $row)
                                    @php $product = $row['product']; @endphp
                                    <tr wire:key="product-{{ $product->id }}">
                                        <td class="product-thumb-cell"><span class="product-thumb" data-initials="{{ mb_strtoupper(mb_substr($product->name, 0, 1)) }}">@if($row['image'])<img src="{{ route('products.hub-media', [$product, $row['image']]) }}" alt="" loading="lazy" onerror="this.remove()">@endif</span></td>
                                        <td><a class="medium" href="{{ route('products.show', $product) }}" wire:navigate>{{ $product->name }}</a><br><span class="muted xs">{{ $product->price ?: 'Price not set' }}</span></td>
                                        <td>{{ $product->brand->name }}</td>
                                        <td><span class="tag {{ $row['source'][0] }}"><span class="dot"></span>{{ $row['source'][1] }}</span></td>
                                        <td><span class="tag {{ $row['tone'] }}"><span class="dot"></span>{{ $row['state'] }}</span></td>
                                        <td><div class="row product-actions"><a class="pill ghost sm" href="{{ $row['url'] }}" wire:navigate>{{ $row['label'] }} <i class="ti ti-arrow-right" aria-hidden="true"></i></a>@include('partials.product-archive-action', ['product' => $product])</div></td>
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
