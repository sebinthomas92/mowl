<div class="app-shell">
    <a class="skip-link" href="#main-content">Skip to products</a>
    @include('partials.workspace-sidebar')
    <div class="app-main">
        @include('partials.top-bar')
        <main id="main-content">
        <section class="library-page">
            <header class="library-header"><div><p class="kicker">OFFER INVENTORY</p><h1>Products</h1><p>Source-linked products ready to become launch packs.</p></div>@if($canCreate)<a class="pill pri" href="{{ route('products.create', array_filter(['brand' => $brand])) }}" wire:navigate><i class="ti ti-plus" aria-hidden="true"></i> Add product</a>@endif</header>
            @if($brands->count() > 1)
                <div class="chips" role="group" aria-label="Brand">
                    <button type="button" class="chip" aria-pressed="{{ $brand ? 'false' : 'true' }}" wire:click="$set('brand', null)">All brands</button>
                    @foreach($brands as $option)
                        <button type="button" class="chip" aria-pressed="{{ $brand === $option->id ? 'true' : 'false' }}" wire:click="$set('brand', {{ $option->id }})">{{ $option->name }}</button>
                    @endforeach
                </div>
            @endif
            @if($products->isEmpty())
                <div class="library-empty"><span>▱</span><h2>{{ $brand ? 'No products for this brand yet' : 'No products yet' }}</h2><p>Add a product from its page or by hand. Saving never starts generation.</p>@if($canCreate)<a href="{{ route('products.create', array_filter(['brand' => $brand])) }}" wire:navigate>Add a product →</a>@endif</div>
            @else
                <div class="data-table" role="table" aria-label="Products">
                    <div class="data-row data-heading" role="row"><span>PRODUCT</span><span>BRAND</span><span>PACKS</span><span>STATUS</span></div>
                    @foreach($products as $product)
                        @php($latestPack = $product->campaignPacks->sortByDesc('updated_at')->first())
                        @php($sourceReady = $product->sourceSnapshots->sortByDesc('fetched_at')->first()?->status === 'ready')
                        <div class="data-row" role="row">
                            <div>
                                <a href="{{ route('products.show', $product) }}"><strong>{{ $product->name }}</strong></a>
                                <small>{{ $product->price ?: 'Price not supplied' }}</small>
                            </div>
                            <span>{{ $product->brand->name }}</span>
                            <span>{{ $product->campaign_packs_count }}</span>
                            <span class="status-dot">● {{ $sourceReady ? 'Product Truth ready' : 'Source review needed' }}</span>
                        </div>
                    @endforeach
                </div>
            @endif
        </section>
        </main>
    </div>
</div>
