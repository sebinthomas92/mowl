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
                                <div class="form-heading"><span>03 / 03</span><h2>Add a product-page source</h2><p>The source is captured, hashed, and linked to every factual claim in the generated pack.</p></div>
                                <label>Product page URL<input wire:model="sourceUrl" type="url" placeholder="https://yourbrand.com/products/your-product" autofocus>@error('sourceUrl')<small class="error">{{ $message }}</small>@enderror</label>
                                @if(config('campaigns.media.uploads_enabled'))
                                    <label class="media-upload">Product images or short videos <small>Optional · up to 8 files</small><input wire:model="mediaUploads" type="file" accept="image/jpeg,image/png,image/webp,video/mp4,video/quicktime,video/webm" multiple>@error('mediaUploads.*')<small class="error">{{ $message }}</small>@enderror</label>
                                    @if($mediaUploads)
                                        <div class="upload-queue">@foreach($mediaUploads as $upload)<span>{{ $upload->getClientOriginalName() }} <small>{{ Illuminate\Support\Number::fileSize($upload->getSize()) }}</small></span>@endforeach</div>
                                    @endif
                                    <div class="upload-progress" wire:loading wire:target="mediaUploads">Uploading media securely…</div>
                                @endif
                                <fieldset class="analysis-choice">
                                    <legend>Analysis depth</legend>
                                    <label><input wire:model="analysisMode" type="radio" value="standard"><span><strong>Standard pack</strong><small>Fast product truth and campaign content</small></span><b>1 credit</b></label>
                                    <label><input wire:model="analysisMode" type="radio" value="deep"><span><strong>Premium deep analysis</strong><small>More deliberate positioning and evidence review</small></span><b>3 credits</b></label>
                                </fieldset>
                                <div class="pipeline-preview">
                                    <span>PAGE</span><i></i><span>TRUTH</span><i></i><span>PACK</span>
                                </div>
                                <button class="primary-button" type="submit" wire:loading.attr="disabled">Generate campaign pack <span wire:loading.remove>✦</span><span wire:loading>Working…</span></button>
                                <p class="cost-note">Credits are reserved when the job is queued and returned automatically if all retries fail.</p>
                            </form>
                        @endif
                    </div>
                </div>
            </section>
        @else
            @php($version = $selectedVersion ? $pack->versions->firstWhere('version', $selectedVersion) : $pack->versions->sortByDesc('version')->first())
            @if(!$version)
                @php($job = $pack->latestGenerationJob)
                <section class="processing-page" wire:poll.3s aria-live="polite"
                    @if($processJobUrl && $job && in_array($job->status, ['queued', 'retrying']))
                        wire:key="process-job-{{ $job->id }}-{{ $job->attempts }}"
                        x-data
                        x-init="fetch(@js($processJobUrl), { method: 'POST', headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content, 'Accept': 'application/json' } }).finally(() => $wire.$refresh())"
                    @endif>
                    <div class="processing-breadcrumbs"><a href="{{ route('campaign-packs.index') }}">Campaign packs</a><span>›</span><strong>{{ $pack->product->name }}</strong></div>
                    <div class="processing-console">
                        <div class="processing-mark"><span>{{ strtoupper(substr($pack->product->name, 0, 2)) }}</span></div>
                        <p class="kicker">CAMPAIGN JOB / {{ str_pad($job?->id ?? 0, 4, '0', STR_PAD_LEFT) }}</p>
                        <h1>{{ $pack->status === 'failed' ? 'Generation needs attention.' : 'Building the campaign pack.' }}</h1>
                        <p>{{ $pack->status === 'failed' ? 'The job exhausted its automatic retries. Your credits were returned.' : 'The source is being captured, normalized, checked, and transformed into evidence-linked campaign content.' }}</p>
                        <div class="processing-steps">
                            @foreach(['fetching_source' => 'Fetch product source', 'processing_media' => 'Prepare product media', 'generating_pack' => 'Generate structured pack', 'complete' => 'Persist approved version'] as $phase => $label)
                                <div @class(['active' => $job?->phase === $phase, 'done' => array_search($phase, array_keys(['fetching_source'=>1,'processing_media'=>1,'generating_pack'=>1,'complete'=>1])) < array_search($job?->phase, array_keys(['fetching_source'=>1,'processing_media'=>1,'generating_pack'=>1,'complete'=>1]))])><i></i><span>{{ $label }}</span></div>
                            @endforeach
                        </div>
                        <div class="job-metadata"><span>Status <strong>{{ str($job?->status ?? $pack->status)->replace('_', ' ')->title() }}</strong></span><span>Mode <strong>{{ ucfirst($pack->analysis_mode) }}</strong></span><span>Credits <strong>{{ $pack->credit_cost }}</strong></span><span>Attempt <strong>{{ $job?->attempts ?? 0 }} / 3</strong></span></div>
                        @if($pack->status === 'failed')
                            <div class="job-error"><strong>{{ $job?->error_code ?: 'Generation failed' }}</strong><p>{{ $job?->error_message ?: 'The source could not be processed.' }}</p></div>
                            <button type="button" class="primary-button retry-button" wire:click="retryGeneration">Retry generation <span>↻</span></button>
                        @else
                            <p class="polling-note">This page updates automatically. You can safely leave and return later.</p>
                        @endif
                    </div>
                </section>
            @else
            @php($content = $version->content)
            <div class="pack-page" id="packs" wire:poll.5s>
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

                @php($activeJob = $pack->latestGenerationJob)
                @if($activeJob && $activeJob->section && in_array($activeJob->status, ['queued', 'processing', 'retrying']))
                    <div class="regeneration-banner"><span><i></i> Regenerating <strong>{{ str($activeJob->section)->replace('_', ' ')->title() }}</strong></span><small>{{ str($activeJob->phase)->replace('_', ' ')->title() }} · attempt {{ $activeJob->attempts }} / 3</small></div>
                @endif

                <section class="version-controls">
                    <div class="version-history"><span>VERSIONS</span>@foreach($pack->versions->sortBy('version') as $availableVersion)<button type="button" wire:click="selectVersion({{ $availableVersion->version }})" @class(['active' => $version->version === $availableVersion->version])>v{{ $availableVersion->version }}</button>@endforeach</div>
                    <form wire:submit="regenerateSection" class="regeneration-form">
                        <label>Regenerate
                            <select wire:model="regenerationSection"><option value="direction">Campaign direction</option><option value="positioning">Positioning</option><option value="meta">Meta copy</option><option value="hooks">Hooks</option><option value="script">Voiceover</option><option value="captions">Captions</option><option value="shot_log">Shot story</option></select>
                        </label>
                        <button type="submit" @disabled($activeJob && in_array($activeJob->status, ['queued', 'processing', 'retrying']))>↻ Generate variation</button>
                    </form>
                    <small>{{ $includedRegenerationsRemaining }} included {{ Str::plural('regeneration', $includedRegenerationsRemaining) }} left · expires {{ $pack->created_at->addDay()->diffForHumans() }}</small>
                    @error('regenerationSection')<span class="error">{{ $message }}</span>@enderror
                </section>

                <div class="pack-layout">
                    <aside class="chapters" aria-label="Pack chapters">
                        <p>CHAPTERS</p>
                        <a href="#direction" class="active">Campaign direction</a>
                        <a href="#truth">Product truth</a>
                        <a href="#positioning">Positioning</a>
                        <a href="#meta">Meta copy</a>
                        <a href="#hooks">Hooks</a>
                        <a href="#voiceover">Voiceover</a>
                        <a href="#shots">Shot story</a>
                        <a href="#captions">Captions</a>
                        <a href="#evidence">Evidence</a>
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
                                <div><small>EVIDENCE</small><strong>{{ count($version->evidence ?? []) }} linked {{ Str::plural('claim', count($version->evidence ?? [])) }}</strong></div>
                            </div>
                        </section>

                        <section id="positioning" class="pack-section">
                            <div class="section-header"><div><span class="section-label">POSITIONING</span><h3>Audiences and benefit angles</h3></div><span class="approved-status">Source constrained</span></div>
                            <div class="positioning-columns"><div><p class="field-label">Audiences</p><ul>@foreach($content['audiences'] as $audience)<li>{{ $audience }}</li>@endforeach</ul></div><div><p class="field-label">Benefits</p><ul>@foreach($content['benefits'] as $benefit)<li>{{ $benefit }}</li>@endforeach</ul></div></div>
                        </section>

                        <section id="meta" class="pack-section">
                            <div class="section-header"><div><span class="section-label">META COPY</span><h3>Ready for Ads Manager</h3></div><span class="approved-status">✓ Approved</span></div>
                            <p class="field-label">Primary text</p>
                            <div class="copy-field"><span>{{ $content['meta']['primary_text'] }}</span><button type="button" aria-label="Copy primary text" @click="navigator.clipboard.writeText(@js($content['meta']['primary_text'])); copied = 'primary'">{{ '▣' }}</button></div>
                            <p class="field-label">Headlines</p>
                            @foreach($content['meta']['headlines'] as $index => $headline)
                                <div class="copy-field compact"><span>{{ $headline }}</span><button type="button" aria-label="Copy headline {{ $index + 1 }}" @click="navigator.clipboard.writeText(@js($headline)); copied = 'headline{{ $index }}'">▣</button></div>
                            @endforeach
                            <p class="field-label">Descriptions</p>
                            @foreach($content['meta']['descriptions'] as $index => $description)
                                <div class="copy-field compact"><span>{{ $description }}</span><button type="button" aria-label="Copy description {{ $index + 1 }}" @click="navigator.clipboard.writeText(@js($description))">▣</button></div>
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

                        <section id="evidence" class="pack-section">
                            <div class="section-header"><div><span class="section-label">CLAIM EVIDENCE</span><h3>What supports the copy</h3></div><span class="approved-status">{{ count($version->evidence ?? []) }} references</span></div>
                            <div class="evidence-list">
                                @forelse($version->evidence ?? [] as $reference)
                                    <article><div><strong>{{ $reference['claim'] }}</strong><span>{{ $reference['status'] }}</span></div><blockquote>{{ $reference['excerpt'] ?? 'Source reference retained.' }}</blockquote><a href="{{ $reference['source'] }}" target="_blank" rel="noopener noreferrer">Open source ↗</a></article>
                                @empty
                                    <p>No claim evidence was returned for this version.</p>
                                @endforelse
                            </div>
                            @if($version->compliance_flags)
                                <div class="compliance-flags"><h4>Compliance flags</h4>@foreach($version->compliance_flags as $flag)<article><strong>{{ ucfirst($flag['severity']) }} · {{ $flag['claim'] }}</strong><p>{{ $flag['reason'] }}</p></article>@endforeach</div>
                            @else
                                <div class="compliance-clear">✓ No unsupported claims were flagged in this version.</div>
                            @endif
                        </section>
                    </article>
                </div>
            </div>
            @endif
        @endif
    </main>
</div>
