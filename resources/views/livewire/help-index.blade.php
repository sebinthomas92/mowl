<div class="app-shell">
    <a class="skip-link" href="#main-content">Skip to help</a>
    @include('partials.workspace-sidebar')
    <div class="app-main">
        @include('partials.top-bar')
        <main id="main-content" class="lib-page">
            <header class="pagehead">
                <div>
                    <div class="eyebrow">Help</div>
                    <h1 class="display">Guides and resources</h1>
                    <p class="muted">How MarketingOwl works, plus the prompt packs in Workflows.</p>
                </div>
            </header>

            <div class="grid">
                @foreach($guides as $guide)
                    <a class="card col c4 help-card" href="{{ route($guide['route']) }}" wire:navigate>
                        <strong class="medium">{{ $guide['title'] }}</strong>
                        <span class="muted small">{{ $guide['text'] }}</span>
                        <span class="small help-open">Open in the app</span>
                    </a>
                @endforeach

                <section class="card col c12">
                    <span class="eyebrow">Prompt library</span>
                    <p class="small">
                        @if($packCount)
                            <span class="num">{{ $packCount }}</span> prompt packs and skills with <span class="num">{{ $promptCount }}</span> prompts ({{ $libraryCategories->implode(', ') }}). Copy one into Claude or your own agent, or download the file.
                        @else
                            Website design and motion prompts for Claude or your own agent live on the prompt showcase.
                        @endif
                    </p>
                    <div class="row">
                        <a class="pill ghost sm" href="{{ route('workflows.index') }}" wire:navigate>Browse in Workflows <i class="ti ti-arrow-right" aria-hidden="true"></i></a>
                        <a class="linkbtn" href="{{ config('workflows.source_url') }}" target="_blank" rel="noopener">Open the prompt showcase</a>
                    </div>
                </section>
            </div>
        </main>
    </div>
</div>
