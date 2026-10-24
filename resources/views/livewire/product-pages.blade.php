<div class="pages-tab" x-data="{ choosing: @js($canEdit && $pages->isEmpty()), ai: true }">
    @if(! $available)
        <div class="empty">
            <strong>Pages are being set up</strong>
            <span class="small muted">The page builder is waiting for a database update. Try again in a few minutes.</span>
        </div>
    @else
        <div class="row pages-head">
            <div class="col pages-head-text">
                <h2 class="pages-title">Landing pages</h2>
                <span class="small muted">Build a page for {{ $product->name }} from a template, let the AI write it from the product's facts, then publish it to your store.</span>
            </div>
            <span class="sp"></span>
            @if($canEdit && $pages->isNotEmpty())
                <button type="button" class="pill pri sm" x-show="!choosing" @click="choosing = true"><i class="ti ti-plus" aria-hidden="true"></i>New page</button>
            @endif
        </div>

        @if($canEdit)
            <section class="card pages-gallery" x-show="choosing" x-cloak aria-labelledby="pages-gallery-title">
                <div class="row pages-gallery-head">
                    <div class="col">
                        <h3 id="pages-gallery-title" class="pages-gallery-title">Pick a template</h3>
                        <span class="small muted">Each preview is already filled from {{ $product->name }}'s product page and Knowledge. Every section, colour and font can be changed in the editor.</span>
                    </div>
                    <span class="sp"></span>
                    @if($pages->isNotEmpty())<button type="button" class="linkbtn" @click="choosing = false">Cancel</button>@endif
                </div>
                <label class="pages-ai">
                    <input type="checkbox" x-model="ai">
                    <span><strong>Write the copy with AI</strong><br><span class="xs muted">Included for the first write of each page. The AI uses only what the product's sources say and flags anything you need to check.</span></span>
                </label>
                <div class="pages-templates">
                    @foreach($templates as $template)
                        <article class="pages-template" wire:key="template-{{ $template['key'] }}">
                            <div class="pages-thumb" x-data="{ s: 0.25 }" x-init="new ResizeObserver((entries) => { s = entries[0].contentRect.width / 1280 }).observe($el)" aria-hidden="true">
                                <iframe src="{{ route('pages.template-preview', [$product, $template['key']]) }}" loading="lazy" sandbox="allow-same-origin" tabindex="-1" title="{{ $template['label'] }} template" :style="'transform:scale(' + s + ')'"></iframe>
                            </div>
                            <div class="col pages-template-body">
                                <strong>{{ $template['label'] }}</strong>
                                <span class="small muted">{{ $template['description'] }}</span>
                                <span class="xs muted">{{ implode(' · ', $template['sections']) }}</span>
                            </div>
                            <button type="button" class="pill dark sm" x-on:click="$wire.create(@js($template['key']), ai)" wire:loading.attr="disabled" wire:target="create">Use {{ $template['label'] }}</button>
                        </article>
                    @endforeach
                </div>
            </section>
        @endif

        @if($pages->isEmpty() && ! $canEdit)
            <div class="empty">
                <strong>No pages yet</strong>
                <span class="small muted">A contributor or owner builds landing pages here. You can review them once they exist.</span>
            </div>
        @endif

        @if($pages->isNotEmpty())
            <div class="pages-list">
                @foreach($pages as $page)
                    @php
                        $live = $page->publish_state === 'live' && $page->live_version === $page->draft_version;
                        $key = $page->publish_state === 'live' && ! $live ? 'idle' : $page->publish_state;
                        [$tone, $label] = \App\Models\Page::STATES[$key] ?? ['', $key];
                        if ($key === 'idle' && $page->live_version) {
                            $label = "Live v{$page->live_version} · draft v{$page->draft_version} not published";
                        }
                        $open = $canEdit ? route('pages.edit', [$product, $page]) : route('pages.preview', [$product, $page]);
                    @endphp
                    <article @class(['card', 'pages-card', 'is-target' => $pageId === $page->id]) wire:key="page-{{ $page->id }}">
                        <a class="pages-card-thumb" href="{{ $open }}" tabindex="-1" aria-hidden="true">
                            <span class="pages-thumb" x-data="{ s: 0.25 }" x-init="new ResizeObserver((entries) => { s = entries[0].contentRect.width / 1280 }).observe($el)">
                                <iframe src="{{ route('pages.preview', [$product, $page, 'thumb' => 1]) }}" loading="lazy" sandbox="allow-same-origin" tabindex="-1" title="{{ $page->name }}" :style="'transform:scale(' + s + ')'"></iframe>
                            </span>
                        </a>
                        <div class="col pages-card-body">
                            <div class="row pages-card-title">
                                <strong>{{ $page->name }}</strong>
                                <span class="tag {{ $tone }}"><span class="dot"></span>{{ $label }}</span>
                            </div>
                            <span class="xs muted">
                                {{ $page->hasDocument() ? ($labels[$page->template] ?? 'Custom').' template' : 'Made before the editor; opening it converts it' }}
                                · draft v{{ $page->draft_version }} · edited {{ $page->updated_at?->diffForHumans() }}
                            </span>
                            @if(preg_match('#^https?://#i', (string) $page->remote_url))
                                <a class="small pages-card-url" href="{{ $page->remote_url }}" target="_blank" rel="noopener">{{ $page->remote_url }}</a>
                            @else
                                <span class="small muted">Not published yet · /{{ $page->slug }}</span>
                            @endif
                            <div class="row pages-card-actions">
                                @if($canEdit)
                                    <a class="pill pri sm" href="{{ route('pages.edit', [$product, $page]) }}"><i class="ti ti-pencil" aria-hidden="true"></i>Open editor</a>
                                @endif
                                <a class="pill ghost sm" href="{{ route('pages.preview', [$product, $page]) }}" target="_blank" rel="noopener"><i class="ti ti-eye" aria-hidden="true"></i>{{ $canEdit ? 'Preview' : 'View draft' }}</a>
                            </div>
                        </div>
                    </article>
                @endforeach
            </div>
        @endif
    @endif
</div>
