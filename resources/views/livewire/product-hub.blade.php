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
                        {{ $label }}@if($key === 'packs' && $packs->isNotEmpty()) <span class="num">· {{ $packs->count() }}</span>@elseif($key === 'campaigns' && $campaigns?->isNotEmpty()) <span class="num">· {{ $campaigns->count() }}</span>@endif
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
                @case('campaigns')
                @case('results')
                    @if(\App\Services\Campaigns\CampaignPlanner::available())
                        <livewire:product-campaigns :product-id="$product->id" :mode="$tab" :key="$tab.'-'.$product->id" />
                    @else
                        <div class="empty"><strong>Campaigns turn on after the next database update</strong><span class="small muted">Approved content and pages keep working in the meantime.</span></div>
                    @endif
                    @break
                @case('hub')
                    @include('product-hubs.tab-hub')
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
