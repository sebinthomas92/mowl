@php($limitReached = $brands->count() >= $brandLimit)
<div class="app-shell">
    <a class="skip-link" href="#main-content">Skip to brands</a>
    @include('partials.workspace-sidebar')
    <div class="app-main">
        @include('partials.top-bar')
        <main id="main-content" class="lib-page">
            <header class="pagehead">
                <div>
                    <div class="eyebrow">{{ $workspace->name }}</div>
                    <h1 class="display">Brands</h1>
                    <p class="muted">Voice, palette and audience flow into every product of a brand. <span class="num">{{ $brands->count() }} of {{ $brandLimit }}</span> brands used.</p>
                </div>
                @if($limitReached)
                    <span class="pill ghost" aria-disabled="true" title="This workspace has reached its brand limit">Brand limit reached</span>
                @else
                    <a class="pill pri" href="{{ route('brands.create') }}" wire:navigate><i class="ti ti-plus" aria-hidden="true"></i> New brand</a>
                @endif
            </header>

            @if(session('status'))<div class="banner ok" role="status"><i class="ti ti-circle-check" aria-hidden="true"></i><span>{{ session('status') }}</span></div>@endif

            @if($brands->isEmpty())
                <div class="empty">
                    <strong class="medium">No brands yet</strong>
                    <span class="muted small">A brand holds the voice, audience and palette its products share. Add one, then add its products.</span>
                    <a class="pill pri sm" href="{{ route('brands.create') }}" wire:navigate><i class="ti ti-plus" aria-hidden="true"></i> New brand</a>
                </div>
            @else
                <div class="grid">
                    @foreach($brands as $brand)
                        <section class="card col c6 brand-card" wire:key="brand-{{ $brand->id }}">
                            <div class="row">
                                <h2 class="display grow">{{ $brand->name }}</h2>
                                <a class="pill ghost sm" href="{{ route('brands.edit', $brand) }}" wire:navigate aria-label="Edit {{ $brand->name }}"><i class="ti ti-pencil" aria-hidden="true"></i> Edit</a>
                            </div>
                            <div>
                                @if($profileAvailable)
                                    <div class="kv"><span class="muted">Voice</span><span>{!! $brand->voice ? e(\Illuminate\Support\Str::limit($brand->voice, 140)) : '<span class="muted">Not set</span>' !!}</span></div>
                                    <div class="kv"><span class="muted">Default audience</span><span>{!! $brand->default_audience ? e($brand->default_audience) : '<span class="muted">Not set</span>' !!}</span></div>
                                @endif
                                <div class="kv"><span class="muted">Website</span><span class="break">@if($brand->website)<a href="{{ $brand->website }}" target="_blank" rel="noopener">{{ preg_replace('#^https?://(www\.)?#', '', rtrim($brand->website, '/')) }}</a>@else<span class="muted">Not set</span>@endif</span></div>
                                <div class="kv"><span class="muted">Products</span><span class="num">{{ $brand->products_count }}</span></div>
                            </div>
                            <div class="row swatches">
                                @forelse($brand->colors() as $color)
                                    <span class="swatch" style="background:{{ $color }}" role="img" aria-label="Brand colour {{ $color }}" title="{{ $color }}"></span>
                                @empty
                                    <span class="muted xs">No palette yet</span>
                                @endforelse
                                @if($brand->colors())<span class="muted xs">Brand palette</span>@endif
                            </div>
                            <div class="row">
                                <a class="pill ghost sm" href="{{ route('products.index', ['brand' => $brand->id]) }}" wire:navigate>View products</a>
                                <a class="linkbtn" href="{{ route('products.create', ['brand' => $brand->id]) }}" wire:navigate>Add a product</a>
                            </div>
                        </section>
                    @endforeach
                </div>
            @endif
        </main>
    </div>
</div>
