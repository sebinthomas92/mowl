<div class="app-shell">
    <a class="skip-link" href="#main-content">Skip to brands</a>
    @include('partials.workspace-sidebar')
    <div class="app-main">
        @include('partials.top-bar')
        <main id="main-content">
        <section class="library-page">
            <header class="library-header"><div><p class="kicker">KNOWLEDGE LIBRARY</p><h1>Brands</h1><p>Every approved product truth and campaign starts with a brand owner.</p></div><a class="primary-link" href="{{ route('campaign-packs.create') }}">＋ New launch pack</a></header>
            <div class="stat-strip"><div><small>ACTIVE BRANDS</small><strong>{{ $brands->count() }}</strong></div><div><small>BETA LIMIT</small><strong>10</strong></div><div><small>AVAILABLE</small><strong>{{ max(0, 10 - $brands->count()) }}</strong></div></div>
            @if($brands->isEmpty())
                <div class="library-empty"><span>◇</span><h2>No brands yet</h2><p>Create your first launch pack to establish the brand and product knowledge chain.</p><a href="{{ route('campaign-packs.create') }}">Create first pack →</a></div>
            @else
                <div class="library-grid">@foreach($brands as $brand)<article class="library-card"><div class="card-monogram">{{ strtoupper(substr($brand->name, 0, 2)) }}</div><div><small>BRAND / {{ str_pad($brand->id, 3, '0', STR_PAD_LEFT) }}</small><h2>{{ $brand->name }}</h2><p>{{ $brand->website ?: 'No website supplied' }}</p></div><footer><span>{{ $brand->products_count }} {{ Str::plural('product', $brand->products_count) }}</span><a href="{{ route('products.index') }}">View products →</a></footer></article>@endforeach</div>
            @endif
        </section>
        </main>
    </div>
</div>
