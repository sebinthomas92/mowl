<div class="client-page">
    <a class="skip-link" href="#main-content">Skip to your work</a>
    <header class="client-head">
        <svg width="30" height="20" viewBox="0 0 30 20" aria-hidden="true"><circle cx="9" cy="10" r="8" fill="#FF5A3D"/><circle cx="21" cy="10" r="8" fill="#EA38D0" opacity=".85"/><circle cx="9" cy="10" r="2.5" fill="#fff"/><circle cx="21" cy="10" r="2.5" fill="#fff"/></svg>
        <div class="grow">
            <strong class="medium">{{ $brand?->name ?? $workspace->name }} · client portal</strong><br>
            <span class="muted xs">Shared by {{ $workspace->name }}</span>
        </div>
        @if($preview)
            <span class="tag rev">Previewing as a client</span>
            <a class="pill ghost sm" href="{{ route('products.index') }}" wire:navigate><i class="ti ti-arrow-left" aria-hidden="true"></i> Back to workspace</a>
        @else
            <span class="tag">Signed in as {{ auth()->user()->name }} · Client</span>
            @if($workspaces->count() > 1)
                <details class="account">
                    <summary class="pill ghost sm">Switch workspace</summary>
                    <div class="menu menu-end" role="menu">
                        @foreach($workspaces as $option)
                            <form method="POST" action="{{ route('workspaces.select', $option) }}">@csrf<button type="submit" role="menuitem" @if($option->id === $workspace->id) aria-current="true" @endif>{{ $option->name }}</button></form>
                        @endforeach
                    </div>
                </details>
            @endif
            <form method="POST" action="{{ route('logout') }}">@csrf<button type="submit" class="pill ghost sm">Sign out</button></form>
        @endif
    </header>

    <main class="client-main" id="main-content">
        @if($preview)
            <div class="banner rev small" role="note">
                <i class="ti ti-eye" aria-hidden="true"></i>
                <span class="grow">This is what a client of {{ $brand?->name ?? 'a brand' }} sees. Clients decide here; your team decides in Review.</span>
                @if($brands->count() > 1)
                    <span class="chips" role="group" aria-label="Brand to preview">
                        @foreach($brands as $option)
                            <button type="button" class="chip" aria-pressed="{{ $brand?->id === $option->id ? 'true' : 'false' }}" wire:click="$set('previewBrand', {{ $option->id }})">{{ $option->name }}</button>
                        @endforeach
                    </span>
                @endif
            </div>
        @endif

        @if($openProduct && $hub)
            <div class="row">
                <a class="pill ghost sm" href="{{ route('portal.index', array_filter(['brand' => $preview ? $brand?->id : null])) }}" wire:navigate><i class="ti ti-arrow-left" aria-hidden="true"></i> All work for {{ $brand->name }}</a>
            </div>
            @if($flash)<div class="banner ok" role="status"><i class="ti ti-circle-check" aria-hidden="true"></i><span>{{ $flash }}</span></div>@endif
            @php($productPending = $pending->where('product_id', $openProduct->id))
            @if($productPending->isNotEmpty())
                <section class="col" aria-labelledby="portal-product-pending">
                    <h2 id="portal-product-pending" class="eyebrow">Waiting for your decision · {{ $productPending->count() }}</h2>
                    @foreach($productPending as $item)
                        @include('client-hub.decision', ['item' => $item, 'imageUrl' => $item->banner_creative_id ? route('portal.image', $item) : null, 'canDecide' => ! $preview])
                    @endforeach
                    <h2 class="eyebrow">Ready to use</h2>
                </section>
            @endif
            @include('client-hub.body', ['product' => $openProduct, 'hub' => $hub, 'heading' => 'h1'])
        @else
        <div>
            <div class="eyebrow">Client portal</div>
            <h1 class="display">Hello, {{ $preview ? 'client' : \Illuminate\Support\Str::before(auth()->user()->name, ' ') }}.</h1>
            @if($brand)
                <p class="muted">{{ $pending->isEmpty() ? 'Nothing needs your decision right now.' : trans_choice(':count piece needs your decision.|:count pieces need your decision.', $pending->count()) }} {{ trans_choice(':count approved piece is ready.|:count approved pieces are ready.', $approved->count()) }}</p>
            @endif
        </div>

        @if(! $brand)
            <div class="empty">
                <strong>{{ $preview ? 'Add a brand to preview the client portal' : 'Your access is not set up yet' }}</strong>
                <span class="muted small">{{ $preview ? 'Clients see the work of one brand.' : 'Ask '.$workspace->name.' to share a brand with you.' }}</span>
            </div>
        @else
            @if($flash)<div class="banner ok" role="status"><i class="ti ti-circle-check" aria-hidden="true"></i><span>{{ $flash }}</span></div>@endif
            @unless($available)
                <div class="banner neu small" role="status"><span>Review items appear here after the next update.</span></div>
            @endunless

            @if($pending->isNotEmpty())
                <section class="col" aria-labelledby="portal-pending">
                    <h2 id="portal-pending" class="eyebrow">Waiting for your decision · {{ $pending->count() }}</h2>
                    @foreach($pending as $item)
                        @include('client-hub.decision', ['item' => $item, 'imageUrl' => $item->banner_creative_id ? route('portal.image', $item) : null, 'canDecide' => ! $preview, 'showProduct' => true])
                    @endforeach
                </section>
            @endif

            @if($hubProducts->isNotEmpty())
                <section class="card col portal-hubs" aria-labelledby="portal-hubs">
                    <h2 id="portal-hubs" class="eyebrow">Ready to use</h2>
                    @foreach($hubProducts as $hubItem)
                        <div class="li" wire:key="portal-hub-{{ $hubItem->id }}">
                            <span class="ic neu"><i class="ti ti-layout-grid" aria-hidden="true"></i></span>
                            <span class="grow small"><strong class="medium">{{ $hubItem->name }}</strong><br><span class="muted xs">Approved copy for Meta, Google, email and social, plus images</span></span>
                            <a class="pill ghost sm" href="{{ route('portal.index', array_filter(['product' => $hubItem->id, 'brand' => $preview ? $brand->id : null])) }}" wire:navigate>Open client hub</a>
                        </div>
                    @endforeach
                </section>
            @endif

            <section class="card col" aria-labelledby="portal-approved">
                <h2 id="portal-approved" class="eyebrow">All approved work</h2>
                @forelse($approved as $item)
                    <div class="li" wire:key="portal-approved-{{ $item->id }}">
                        <span class="portal-thumb">@include('content.thumb', ['item' => $item, 'height' => 52, 'imageUrl' => $item->banner_creative_id ? route('portal.image', $item) : null])</span>
                        <span class="grow small"><strong class="medium">{{ $item->title }}</strong> · version {{ $item->current_version }}<br><span class="muted xs">{{ $item->product->name }}@if($item->latestVersion?->reviewed_by_name) · approved by {{ $item->latestVersion->reviewed_by_name }}@endif</span></span>
                        @if($item->banner_creative_id)
                            <a class="pill ghost sm" href="{{ route('portal.image', [$item, 'download' => 1]) }}">Download</a>
                        @else
                            <details class="portal-details"><summary class="pill ghost sm">Read</summary><div class="client-preview col">@include('content.preview', ['item' => $item, 'body' => $item->latestVersion?->body ?? []])</div></details>
                        @endif
                    </div>
                @empty
                    <p class="muted small">{{ $workspace->name }} is preparing your first work.</p>
                @endforelse
                @foreach($pages as $page)
                    <div class="li" wire:key="portal-page-{{ $page->id }}">
                        <span class="ic neu"><i class="ti ti-link" aria-hidden="true"></i></span>
                        <span class="grow small"><strong class="medium">{{ $page->name }}</strong> · version {{ $page->live_version }}<br><span class="muted xs portal-url">{{ $page->product->name }} · live{{ $page->verified_at ? ', verified '.$page->verified_at->format('j M, H:i') : '' }}</span></span>
                        <a class="pill ghost sm" href="{{ $page->remote_url }}" target="_blank" rel="noopener">Open page</a>
                    </div>
                @endforeach
            </section>
        @endif
        @endif
        <p class="muted xs">Clients never see generation costs, drafts, internal knowledge or other brands.</p>
    </main>
</div>
