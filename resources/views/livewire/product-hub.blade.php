@php($isPublic = false)
@php($shareToken = null)
<div class="app-shell">
    <a class="skip-link" href="#main-content">Skip to product</a>
    @include('partials.workspace-sidebar')
    <div class="app-main">
        @include('partials.top-bar')
        <main id="main-content" class="product-page">
            @include('product-hubs.hero')

            <nav class="tabs" aria-label="Product sections">
                @foreach($tabs as $key => $label)
                    <a href="{{ route('products.show', [$product, 'tab' => $key]) }}" wire:navigate @class(['cur' => $tab === $key]) @if($tab === $key) aria-current="page" @endif>
                        {{ $label }}@if($key === 'packs' && $packs->isNotEmpty()) <span class="num">· {{ $packs->count() }}</span>@endif
                    </a>
                @endforeach
            </nav>

            @if(session('knowledge-status'))<div class="banner ok" role="status"><i class="ti ti-circle-check" aria-hidden="true"></i><span>{{ session('knowledge-status') }}</span></div>@endif
            @if(session('knowledge-error'))<div class="banner bad" role="alert"><i class="ti ti-alert-triangle" aria-hidden="true"></i><span>{{ session('knowledge-error') }}</span></div>@endif

            @switch($tab)
                @case('knowledge')
                    @include('product-hubs.tab-knowledge')
                    @break
                @case('content')
                    @if(\App\Services\ContentLibrary::available())
                        <livewire:content-browser :product-id="$product->id" :key="'content-'.$product->id" />
                    @else
                        <div class="empty"><strong>Content items turn on after the next database update</strong><span class="small muted">Until then, approved launch packs show in the Client hub tab.</span></div>
                    @endif
                    @break
                @case('pages')
                    @if(\App\Services\Pages\PageBuilder::available())
                        <livewire:product-pages :product-id="$product->id" :key="'pages-'.$product->id" />
                    @else
                        <div class="empty"><strong>Pages turn on after the next database update</strong><span class="small muted">Approved content stays in the Content tab until then.</span></div>
                    @endif
                    @break
                @case('hub')
                    <div class="product-hub-shell hub-embed" x-data="productHub()">
                        @include('product-hubs.surface')
                    </div>
                    @break
                @case('packs')
                    @include('product-hubs.tab-packs')
                    @break
                @default
                    @include('product-hubs.tab-overview')
            @endswitch
        </main>
    </div>
</div>

@script
<script>
    Alpine.data('productHub', () => ({
        section: 'overview', campaign: 'search', copied: null, mobileNav: false,
        async copy(value, key) {
            await navigator.clipboard.writeText(value);
            this.copied = key;
            setTimeout(() => { if (this.copied === key) this.copied = null }, 1600);
        }
    }))
</script>
@endscript
