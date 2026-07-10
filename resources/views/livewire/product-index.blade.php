<div class="app-shell" x-data="{ mobileNav: false }">
    <a class="skip-link" href="#main-content">Skip to products</a>
    @include('partials.workspace-sidebar')
    <main class="main-panel" id="main-content">
        <header class="mobile-header"><button type="button" @click="mobileNav = !mobileNav" :aria-expanded="mobileNav.toString()" aria-label="Toggle navigation">☰</button><div><img src="/marketing-owl-logo.png" alt=""> Marketing Owl</div><span>Beta</span></header>
        <section class="library-page">
            <header class="library-header"><div><p class="kicker">OFFER INVENTORY</p><h1>Products</h1><p>Source-linked products ready to become campaign packs.</p></div><a class="primary-link" href="{{ route('campaign-packs.create') }}">＋ Add through new pack</a></header>
            @if($products->isEmpty())
                <div class="library-empty"><span>▱</span><h2>No products yet</h2><p>Add a product and source page through the campaign pack builder.</p><a href="{{ route('campaign-packs.create') }}">Add first product →</a></div>
            @else
                <div class="data-table" role="table" aria-label="Products"><div class="data-row data-heading" role="row"><span>PRODUCT</span><span>BRAND</span><span>PACKS</span><span>STATUS</span></div>@foreach($products as $product)<div class="data-row" role="row"><div><strong>{{ $product->name }}</strong><small>{{ $product->price ?: 'Price not supplied' }}</small></div><span>{{ $product->brand->name }}</span><span>{{ $product->campaign_packs_count }}</span><span class="status-dot">● Source ready</span></div>@endforeach</div>
            @endif
        </section>
    </main>
</div>
