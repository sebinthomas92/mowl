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
            @if($brands->count() > 1)
                <div class="chips" role="group" aria-label="Brand">
                    <button type="button" class="chip" aria-pressed="{{ $brand ? 'false' : 'true' }}" wire:click="$set('brand', null)">All brands</button>
                    @foreach($brands as $option)
                        <button type="button" class="chip" aria-pressed="{{ $brand === $option->id ? 'true' : 'false' }}" wire:click="$set('brand', {{ $option->id }})">{{ $option->name }}</button>
                    @endforeach
                </div>
            @endif
            @if($products->isEmpty())
                <div class="empty">
                    <strong>{{ $brand ? 'No products for this brand yet' : 'Add your first product' }}</strong>
                    <span class="small muted">Paste the product's web page and we read its name, price and details for you to confirm. Saving a product never starts generation or uses credits.</span>
                    @if($canCreate)<a class="pill pri sm" href="{{ route('products.create', array_filter(['brand' => $brand])) }}" wire:navigate><i class="ti ti-plus" aria-hidden="true"></i> Add product</a>@endif
                </div>
            @else
                <div class="tw">
                    <table class="tbl">
                        <thead><tr><th scope="col">Product</th><th scope="col">Brand</th><th scope="col">Where it stands</th><th scope="col">Next step</th></tr></thead>
                        <tbody>
                            @foreach($products as $product)
                                @php
                                    $latestPack = $product->campaignPacks->sortByDesc('id')->first();
                                    $hasApproved = $product->campaignPacks->contains(fn ($pack) => $pack->versions->contains('review_status', 'approved'));
                                    $show = fn (string $tab = 'overview') => route('products.show', [$product, 'tab' => $tab]);
                                    [$tone, $state, $label, $url] = match (true) {
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
                                @endphp
                                <tr wire:key="product-{{ $product->id }}">
                                    <td><a class="medium" href="{{ route('products.show', $product) }}" wire:navigate>{{ $product->name }}</a><br><span class="muted xs">{{ $product->price ?: 'Price not set' }}</span></td>
                                    <td>{{ $product->brand->name }}</td>
                                    <td><span class="tag {{ $tone }}"><span class="dot"></span>{{ $state }}</span></td>
                                    <td><a class="pill ghost sm" href="{{ $url }}" wire:navigate>{{ $label }} <i class="ti ti-arrow-right" aria-hidden="true"></i></a></td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </main>
    </div>
</div>
