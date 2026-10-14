<div class="app-shell">
    <a class="skip-link" href="#main-content">Skip to library</a>
    @include('partials.workspace-sidebar')
    <div class="app-main">
        @include('partials.top-bar')
        <main id="main-content" class="lib-page">
            <div class="pagehead">
                <div>
                    <div class="eyebrow">All brands</div>
                    <h1 class="display">Library</h1>
                    <p class="muted">Every image, video script and copy set across products. The same records you see inside each product.</p>
                </div>
            </div>
            @if($available)
                <livewire:content-browser />
            @else
                <div class="empty"><strong>The library turns on after the next database update</strong><span class="small muted">Launch packs still work as before in the meantime.</span></div>
            @endif
        </main>
    </div>
</div>
