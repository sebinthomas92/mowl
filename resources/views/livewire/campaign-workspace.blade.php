<div class="app-shell" x-data="{ mobileNav: false, copied: '' }">
    <a class="skip-link" href="#main-content">Skip to campaign workspace</a>
    @include('partials.workspace-sidebar')

    <main class="main-panel" id="main-content">
        <header class="mobile-header">
            <button type="button" @click="mobileNav = !mobileNav" :aria-expanded="mobileNav.toString()" aria-label="Toggle navigation">☰</button>
            <div><img src="/marketing-owl-logo.png" alt=""> Marketing Owl</div>
            <span>Beta</span>
        </header>

        @if(!$pack)
            <section class="onboarding">
                <div class="onboarding-topline">
                    <span class="eyebrow">Campaign intelligence workspace</span>
                    <span class="beta-pill">Paid beta · 50 credits</span>
                </div>
                <div class="onboarding-heading">
                    <div>
                        <p class="kicker">BUILD YOUR FIRST PACK</p>
                        <h1>From product page<br>to <em>approved campaign.</em></h1>
                        <p>Capture the truth once. Turn it into evidence-linked copy, hooks, scripts, captions, and a shot plan your media buyers can use immediately.</p>
                    </div>
                    <div class="trust-card">
                        <span>PACK STANDARD</span>
                        <strong>Source-linked</strong>
                        <p>Claims stay tied to supplied evidence. Unsupported claims are flagged before approval.</p>
                    </div>
                </div>

                <div class="setup-grid">
                    <ol class="setup-steps" aria-label="Pack setup progress">
                        <li class="{{ $step >= 1 ? 'active' : '' }} {{ $step > 1 ? 'done' : '' }}"><b>01</b><span><strong>Brand</strong><small>Create the knowledge owner</small></span></li>
                        <li class="{{ $step >= 2 ? 'active' : '' }} {{ $step > 2 ? 'done' : '' }}"><b>02</b><span><strong>Product</strong><small>Name the offer</small></span></li>
                        <li class="{{ $step >= 3 ? 'active' : '' }}"><b>03</b><span><strong>Source</strong><small>Anchor the campaign truth</small></span></li>
                    </ol>

                    <div class="form-card">
                        @if($step === 1)
                            @if($brands->isNotEmpty())
                                <form wire:submit="useBrand" class="existing-brand-form">
                                    <div class="form-heading"><span>01 / 03</span><h2>Choose a brand</h2><p>Reuse approved brand knowledge or create a new brand below.</p></div>
                                    <label>Existing brand
                                        <select wire:model="brandId">
                                            <option value="">Select a brand</option>
                                            @foreach($brands as $brand)
                                                <option value="{{ $brand->id }}">{{ $brand->name }}</option>
                                            @endforeach
                                        </select>
                                        @error('brandId')<small class="error">Choose a brand to continue.</small>@enderror
                                    </label>
                                    <button class="primary-button" type="submit">Use this brand <span>→</span></button>
                                </form>
                                <div class="form-divider"><span>OR CREATE NEW</span></div>
                            @endif
                            <form wire:submit="saveBrand" class="new-brand-form">
                                @if($brands->isEmpty())
                                <div class="form-heading"><span>01 / 03</span><h2>Create a brand</h2><p>Brands keep product knowledge and campaign history separated.</p></div>
                                @endif
                                <label>Brand name<input wire:model="brandName" type="text" placeholder="e.g. Plush Republic" autofocus>@error('brandName')<small class="error">{{ $message }}</small>@enderror</label>
                                <label>Brand website <small>Optional</small><input wire:model="brandWebsite" type="url" placeholder="https://yourbrand.com">@error('brandWebsite')<small class="error">{{ $message }}</small>@enderror</label>
                                <button class="primary-button" type="submit">Create brand <span>→</span></button>
                            </form>
                        @elseif($step === 2)
                            <form wire:submit="saveProduct">
                                <div class="form-heading"><span>02 / 03</span><h2>Add the product</h2><p>Give the pack a clear commercial focus.</p></div>
                                <label>Product name<input wire:model="productName" type="text" placeholder="e.g. Book-Shaped Kindle Stand" autofocus>@error('productName')<small class="error">{{ $message }}</small>@enderror</label>
                                <div class="field-row">
                                    <label>Price <small>Optional</small><input wire:model="productPrice" type="text" placeholder="₹899"></label>
                                    <label>Short context <small>Optional</small><input wire:model="productSummary" type="text" placeholder="What makes it useful?"></label>
                                </div>
                                <button class="primary-button" type="submit">Save product <span>→</span></button>
                            </form>
                        @else
                            <form wire:submit="generatePack">
                                <div class="form-heading"><span>03 / 03</span><h2>Add a product-page source</h2><p>This beta simulates extraction but persists the source and content hash for the real pipeline.</p></div>
                                <label>Product page URL<input wire:model="sourceUrl" type="url" placeholder="https://yourbrand.com/products/your-product" autofocus>@error('sourceUrl')<small class="error">{{ $message }}</small>@enderror</label>
                                <div class="pipeline-preview">
                                    <span>PAGE</span><i></i><span>TRUTH</span><i></i><span>PACK</span>
                                </div>
                                <button class="primary-button" type="submit" wire:loading.attr="disabled">Generate campaign pack <span wire:loading.remove>✦</span><span wire:loading>Working…</span></button>
                                <p class="cost-note">Uses 1 pack credit · mock cost tracked at $0.018</p>
                            </form>
                        @endif
                    </div>
                </div>
            </section>
        @else
            @php($version = $pack->versions->sortByDesc('version')->first())
            @php($content = $version->content)
            <div class="pack-page" id="packs">
                <header class="pack-toolbar">
                    <div class="breadcrumbs"><span>{{ $pack->product->brand->name }}</span><b>›</b><span>{{ $pack->product->name }}</span><b>›</b><strong>Campaign Pack v{{ $version->version }}</strong></div>
                    <div class="toolbar-actions">
                        <span class="verified-chip">✓ Claims source-linked</span>
                        <button type="button" class="secondary-button" wire:click="startAnother">＋ New pack</button>
                        <button type="button" class="copy-pack" @click="navigator.clipboard.writeText(@js(json_encode($content, JSON_PRETTY_PRINT))); copied = 'pack'; setTimeout(() => copied = '', 1600)"><span x-text="copied === 'pack' ? '✓ Copied' : '▣ Copy approved pack'"></span></button>
                    </div>
                </header>

                <section class="product-hero">
                    <div class="product-art" aria-hidden="true"><div class="art-card"><span>CAMPAIGN</span><b>{{ strtoupper(substr($pack->product->name, 0, 2)) }}</b><i></i></div></div>
                    <div><p class="pack-number">PACK / {{ str_pad($pack->id, 4, '0', STR_PAD_LEFT) }}</p><h1>{{ $pack->product->name }}</h1><p>{{ $pack->product->brand->name }} <i>•</i> {{ $pack->product->price ?: 'Price not supplied' }} <i>•</i> Campaign Pack v{{ $version->version }}</p><div class="approval-line"><span>✓</span><strong>Approved</strong><i></i><small>Generated {{ $pack->updated_at->format('M j, Y') }}</small><i></i><small>${{ number_format($pack->estimated_cost, 3) }} tracked cost</small></div></div>
                </section>

                <div class="pack-layout">
                    <aside class="chapters" aria-label="Pack chapters">
                        <p>CHAPTERS</p>
                        <a href="#direction" class="active">Campaign direction</a>
                        <a href="#truth">Product truth</a>
                        <a href="#meta">Meta copy</a>
                        <a href="#hooks">Hooks</a>
                        <a href="#voiceover">Voiceover</a>
                        <a href="#shots">Shot story</a>
                        <a href="#captions">Captions</a>
                    </aside>

                    <article class="pack-content">
                        <section id="direction" class="direction-section">
                            <p class="section-label">CAMPAIGN DIRECTION</p>
                            <h2>{{ $content['direction']['title'] }}</h2>
                            <p>{{ $content['direction']['summary'] }}</p>
                        </section>

                        <section id="truth" class="truth-section pack-section">
                            <div class="section-header"><div><span class="section-label">PRODUCT TRUTH</span><h3>What the source supports</h3></div><span class="approved-status">✓ Source-linked</span></div>
                            <div class="truth-grid">
                                <div><small>PRODUCT</small><strong>{{ $content['product_truth']['name'] }}</strong></div>
                                <div><small>PRICE</small><strong>{{ $content['product_truth']['price'] }}</strong></div>
                                <div><small>EVIDENCE</small><strong>1 approved source</strong></div>
                            </div>
                        </section>

                        <section id="meta" class="pack-section">
                            <div class="section-header"><div><span class="section-label">META COPY</span><h3>Ready for Ads Manager</h3></div><span class="approved-status">✓ Approved</span></div>
                            <p class="field-label">Primary text</p>
                            <div class="copy-field"><span>{{ $content['meta']['primary_text'] }}</span><button type="button" aria-label="Copy primary text" @click="navigator.clipboard.writeText(@js($content['meta']['primary_text'])); copied = 'primary'">{{ '▣' }}</button></div>
                            <p class="field-label">Headlines</p>
                            @foreach($content['meta']['headlines'] as $index => $headline)
                                <div class="copy-field compact"><span>{{ $headline }}</span><button type="button" aria-label="Copy headline {{ $index + 1 }}" @click="navigator.clipboard.writeText(@js($headline)); copied = 'headline{{ $index }}'">▣</button></div>
                            @endforeach
                        </section>

                        <section id="hooks" class="pack-section">
                            <div class="section-header"><div><span class="section-label">HOOK BANK</span><h3>Fast openings for testing</h3></div><span class="count-badge">{{ count($content['hooks']) }} OPTIONS</span></div>
                            <div class="hook-grid">@foreach($content['hooks'] as $index => $hook)<div><b>0{{ $index + 1 }}</b><p>{{ $hook }}</p><button type="button" aria-label="Copy hook {{ $index + 1 }}" @click="navigator.clipboard.writeText(@js($hook))">▣</button></div>@endforeach</div>
                        </section>

                        <section id="voiceover" class="pack-section">
                            <div class="section-header"><div><span class="section-label">VOICEOVER</span><h3>20-second product story</h3></div><span class="approved-status">20 sec &nbsp; ✓ Approved</span></div>
                            <div class="script-table">@foreach($content['script'] as $line)<div><time>{{ $line['time'] }}</time><p>{{ $line['line'] }}</p><button type="button" aria-label="Copy script line" @click="navigator.clipboard.writeText(@js($line['line']))">▣</button></div>@endforeach</div>
                        </section>

                        <section id="shots" class="pack-section two-column-section">
                            <div><span class="section-label">SHOT STORY</span><h3>What to capture</h3></div>
                            <ol>@foreach($content['shot_log'] as $shot)<li>{{ $shot }}</li>@endforeach</ol>
                        </section>

                        <section id="captions" class="pack-section two-column-section">
                            <div><span class="section-label">CAPTIONS</span><h3>Organic-ready</h3></div>
                            <div>@foreach($content['captions'] as $caption)<div class="copy-field compact"><span>{{ $caption }}</span><button type="button" aria-label="Copy caption" @click="navigator.clipboard.writeText(@js($caption))">▣</button></div>@endforeach</div>
                        </section>
                    </article>
                </div>
            </div>
        @endif
    </main>
</div>
