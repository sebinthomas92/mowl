<div class="app-shell" x-data="{ mobileNav: false, copied: '', bannerConfirm: false, activeRoute: 0 }">
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
                                <div class="form-heading"><span>02 / 03</span><h2>Add the product page</h2><p>Paste a public product URL. We’ll pull the product details for you.</p></div>
                                <label>Product page URL<input wire:model="productUrl" type="url" placeholder="https://yourbrand.com/products/your-product" autofocus>@error('productUrl')<small class="error">{{ $message }}</small>@enderror</label>
                                <button class="source-fetch-button" type="button" wire:click="loadProductFromUrl" wire:loading.attr="disabled" wire:target="loadProductFromUrl">
                                    <span wire:loading.remove wire:target="loadProductFromUrl">{{ $productDetailsLoaded ? 'Refresh product details' : 'Get product details' }}</span>
                                    <span wire:loading wire:target="loadProductFromUrl">Reading product page…</span>
                                    <b aria-hidden="true">↗</b>
                                </button>
                                @if($productDetailsLoaded)
                                    <section class="product-import-preview" aria-live="polite">
                                        <span>PRODUCT FOUND</span>
                                        <h3>{{ $productName }}</h3>
                                        <div><strong>{{ $productPrice ?: 'Price not listed' }}</strong><small>{{ parse_url($productUrl, PHP_URL_HOST) }}</small></div>
                                        <p>{{ $productSummary ?: 'No product description was available on the page.' }}</p>
                                    </section>
                                    <button class="primary-button" type="submit" wire:loading.attr="disabled" wire:target="saveProduct">Use this product <span>→</span></button>
                                @endif
                            </form>
                        @else
                            <form wire:submit="generatePack">
                                <div class="form-heading"><span>03 / 03</span><h2>Add a product-page source</h2><p>The source is captured, hashed, and linked to every factual claim in the generated pack.</p></div>
                                <label>Product page URL<input wire:model="sourceUrl" type="url" placeholder="https://yourbrand.com/products/your-product" autofocus>@error('sourceUrl')<small class="error">{{ $message }}</small>@enderror</label>
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
            @php
                $version = $selectedVersion ? $pack->versions->firstWhere('version', $selectedVersion) : $pack->versions->sortByDesc('version')->first();
            @endphp
            @if(!$version)
                @php
                    $job = $pack->latestGenerationJob;
                @endphp
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
                            @foreach(['fetching_source' => 'Refresh Product Truth', 'generating_pack' => 'Rank angles and build routes', 'complete' => 'Validate and save version'] as $phase => $label)
                                <div @class(['active' => $job?->phase === $phase, 'done' => array_search($phase, array_keys(['fetching_source'=>1,'generating_pack'=>1,'complete'=>1])) < array_search($job?->phase, array_keys(['fetching_source'=>1,'generating_pack'=>1,'complete'=>1]))])><i></i><span>{{ $label }}</span></div>
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
            @php
                $content = $displayContent;
                $isOwner = $workspace->users()->whereKey(auth()->id())->wherePivot('role', 'owner')->exists();
            @endphp
            <div class="pack-page" id="packs" wire:poll.5s>
                <header class="pack-toolbar">
                    <div class="breadcrumbs"><span>{{ $pack->product->brand->name }}</span><b>›</b><span>{{ $pack->product->name }}</span><b>›</b><strong>Campaign Pack v{{ $version->version }}</strong></div>
                    <div class="toolbar-actions">
                        <span @class(['verified-chip', 'qa-blocked-chip' => $qaIssues])>{{ $qaIssues ? '✕ QA blocked' : '✓ Claims and timing checked' }}</span>
                        <button type="button" class="secondary-button" wire:click="startAnother">＋ New pack</button>
                        <button type="button" class="copy-pack" @click="navigator.clipboard.writeText(@js(json_encode($content, JSON_PRETTY_PRINT))); copied = 'pack'; setTimeout(() => copied = '', 1600)"><span x-text="copied === 'pack' ? '✓ Copied' : '▣ Copy pack'"></span></button>
                    </div>
                </header>

                <section class="product-hero">
                    <div class="product-art" aria-hidden="true"><div class="art-card"><span>CAMPAIGN</span><b>{{ strtoupper(substr($pack->product->name, 0, 2)) }}</b><i></i></div></div>
                    <div><p class="pack-number">PACK / {{ str_pad($pack->id, 4, '0', STR_PAD_LEFT) }}</p><h1>{{ $pack->product->name }}</h1><p>{{ $pack->product->brand->name }} <i>•</i> {{ $pack->product->price ?: 'Price not supplied' }} <i>•</i> Campaign Pack v{{ $version->version }}</p><div class="approval-line"><span>{{ $version->review_status === 'approved' ? '✓' : '•' }}</span><strong>{{ str($version->review_status)->title() }}</strong><i></i><small>{{ $version->reviewed_at ? 'Reviewed '.$version->reviewed_at->format('M j, Y') : 'Generated '.$version->created_at->format('M j, Y') }}</small><i></i><small>${{ number_format($pack->estimated_cost, 3) }} tracked cost</small></div></div>
                </section>

                @php
                    $activeJob = $pack->latestGenerationJob;
                @endphp
                @if($processJobUrl && $activeJob && in_array($activeJob->status, ['queued', 'retrying']))
                    <div
                        wire:key="process-job-{{ $activeJob->id }}-{{ $activeJob->attempts }}"
                        x-data
                        x-init="fetch(@js($processJobUrl), { method: 'POST', headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content, 'Accept': 'application/json' } }).finally(() => $wire.$refresh())"
                    ></div>
                @endif
                @if($activeJob && $activeJob->section && in_array($activeJob->status, ['queued', 'processing', 'retrying']))
                    <div class="regeneration-banner"><span><i></i> Regenerating <strong>{{ str($activeJob->section)->replace('_', ' ')->title() }}</strong></span><small>{{ str($activeJob->phase)->replace('_', ' ')->title() }} · attempt {{ $activeJob->attempts }} / 3</small></div>
                @endif

                <section class="version-controls">
                    <div class="version-history"><span>VERSIONS</span>@foreach($pack->versions->sortBy('version') as $availableVersion)<button type="button" wire:click="selectVersion({{ $availableVersion->version }})" @class(['active' => $version->version === $availableVersion->version])>v{{ $availableVersion->version }}</button>@endforeach</div>
                    <form wire:submit="regenerateSection" class="regeneration-form">
                        <label>Regenerate
                            <select wire:model="regenerationSection"><option value="positioning">Positioning</option><option value="ranked_angles">Ranked angles</option><option value="creative_routes">Creative routes</option><option value="offers">Offer framing</option></select>
                        </label>
                        <button type="submit" @disabled($activeJob && in_array($activeJob->status, ['queued', 'processing', 'retrying']))>↻ Generate variation</button>
                    </form>
                    <small>{{ $includedRegenerationsRemaining }} included {{ Str::plural('regeneration', $includedRegenerationsRemaining) }} left · expires {{ $pack->created_at->addDay()->diffForHumans() }}</small>
                    @error('regenerationSection')<span class="error">{{ $message }}</span>@enderror
                </section>

                @if($reviewFeaturesAvailable)
                <section class="review-panel">
                    <div><p class="section-label">HUMAN REVIEW</p><h2>{{ $version->review_status === 'approved' ? 'This version is locked.' : 'Review before media buyers use this version.' }}</h2><p>{{ $version->review_note ?: 'Draft changes remain separate from approved versions.' }}</p></div>
                    <div class="review-actions">
                        @if($version->review_status === 'draft')<button type="button" class="secondary-button" wire:click="requestReview">Send to review</button>@endif
                        @if($isOwner && in_array($version->review_status, ['draft', 'review']))
                            <input wire:model="reviewNote" aria-label="Approval or rejection note" placeholder="Review note (required to reject)">
                            <button type="button" class="secondary-button" wire:click="rejectVersion">Reject</button><button type="button" class="copy-pack" wire:click="approveVersion" @disabled($qaIssues)>{{ $qaIssues ? 'Approval blocked' : 'Approve & lock' }}</button>
                        @endif
                        @if($isOwner && $version->review_status === 'approved')<button type="button" class="secondary-button" wire:click="createShare">Create 7-day share link</button>@endif
                    </div>
                    @if($shareUrl)<p class="share-result">Share link: <a href="{{ $shareUrl }}" target="_blank" rel="noopener">{{ $shareUrl }}</a></p>@endif
                    @error('reviewNote')<p class="approval-error" role="alert">{{ $message }}</p>@enderror
                </section>

                <section class="review-panel compact-review">
                    <div><p class="section-label">SOURCE TRUTH</p><h3>{{ $pack->sourceSnapshot->approved_at ? 'Source approved '.$pack->sourceSnapshot->approved_at->format('M j, Y') : 'Source needs an owner approval' }}</h3><p>{{ $pack->sourceSnapshot->url }} · {{ $pack->product->sourceSnapshots()->count() }} source snapshots retained</p></div>
                    @if($isOwner && ! $pack->sourceSnapshot->approved_at)<button type="button" class="secondary-button" wire:click="approveSource">Approve source truth</button>@endif
                </section>

                @if($version->review_status === 'approved')
                    <section class="export-panel"><span>EXPORT APPROVED VERSION</span><a href="{{ route('campaign-packs.export', [$pack, $version, 'pdf']) }}">Complete pack</a><a href="{{ route('campaign-packs.export', [$pack, $version, 'voiceover']) }}">Voiceover sheet</a><a href="{{ route('campaign-packs.export', [$pack, $version, 'captions']) }}">Timed captions</a><a href="{{ route('campaign-packs.export', [$pack, $version, 'shot-plan']) }}">Shot plan</a>@foreach($shares->whereNull('revoked_at') as $share)<button type="button" wire:click="revokeShare({{ $share->id }})">Revoke share</button>@endforeach</section>
                @endif

                <section class="comments-panel"><div><p class="section-label">REVIEW NOTES</p><h3>{{ $version->comments->count() }} comments on this version</h3></div><form wire:submit="addComment"><input wire:model="commentSection" placeholder="Section (optional)"><textarea wire:model="commentBody" placeholder="Leave a precise review note"></textarea><button type="submit">Add note</button></form>@foreach($version->comments as $comment)<article><strong>{{ $comment->user->name }}</strong>@if($comment->section)<span>{{ $comment->section }}</span>@endif<p>{{ $comment->body }}</p></article>@endforeach</section>
                @endif

                <div class="pack-layout">
                    <aside class="chapters" aria-label="Pack chapters">
                        <p>CHAPTERS</p>
                        <a href="#overview" class="active">Overview</a>
                        <a href="#product-truth">Product Truth</a>
                        <a href="#positioning">Positioning</a>
                        <a href="#ranked-angles">Ranked Angles</a>
                        <a href="#creative-routes">Creative Routes</a>
                        <a href="#shot-plans">Shot Plans</a>
                        <a href="#platform-assets">Platform Assets</a>
                        <a href="#banner-studio">Banner Studio</a>
                        <a href="#offers">Offers</a>
                        <a href="#qa-approval">QA & Approval</a>
                        <a href="#versions-exports">Versions & Exports</a>
                    </aside>

                    <article class="pack-content">
                        <section id="overview" class="direction-section workspace-overview">
                            <p class="section-label">PRODUCT WORKSPACE</p>
                            <h2>{{ $content['overview']['campaign_goal'] }}</h2>
                            <p>{{ $content['overview']['summary'] }}</p>
                        </section>

                        <section id="product-truth" class="truth-section pack-section">
                            <div class="section-header"><div><span class="section-label">PRODUCT TRUTH</span><h3>Verified facts, limits, and sources</h3></div><span class="approved-status">{{ $pack->sourceSnapshot->approved_at ? '✓ Owner-approved' : 'Approval needed' }}</span></div>
                            <div class="truth-grid product-truth-summary">
                                <div><small>PRODUCT</small><strong>{{ $content['product_truth']['name'] }}</strong></div>
                                <div><small>PRICE</small><strong>{{ $content['product_truth']['price'] }}</strong></div>
                                <div><small>AVAILABILITY</small><strong>{{ $content['product_truth']['availability'] }}</strong></div>
                                <div><small>EVIDENCE</small><strong>{{ count($version->evidence ?? []) }} classified {{ Str::plural('claim', count($version->evidence ?? [])) }}</strong></div>
                            </div>
                            <div class="truth-detail-grid">
                                <div><p class="field-label">Verified facts</p><ul>@forelse($content['product_truth']['verified_facts'] as $fact)<li>{{ $fact['statement'] }}</li>@empty<li>No verified facts were extracted.</li>@endforelse</ul></div>
                                <div><p class="field-label">Supported benefits</p><ul>@forelse($content['product_truth']['supported_benefits'] as $benefit)<li>{{ $benefit }}</li>@empty<li>No supported benefits were confirmed.</li>@endforelse</ul></div>
                                <div><p class="field-label">Offers & trust signals</p><ul>@forelse($content['product_truth']['offers_and_trust_signals'] as $signal)<li>{{ $signal }}</li>@empty<li>No offer or trust signal was confirmed.</li>@endforelse</ul></div>
                                <div><p class="field-label">Brand context</p><ul>@foreach($content['product_truth']['brand_context'] as $context)<li>{{ $context }}</li>@endforeach</ul></div>
                                <div class="truth-warning"><p class="field-label">Missing or unknown</p><ul>@foreach($content['product_truth']['missing_information'] as $missing)<li>{{ $missing }}</li>@endforeach</ul></div>
                                <div class="truth-warning"><p class="field-label">Claims that must not be made</p><ul>@foreach($content['product_truth']['prohibited_claims'] as $claim)<li>{{ $claim }}</li>@endforeach</ul></div>
                            </div>
                            <div class="source-links"><span>SOURCES</span>@foreach($content['product_truth']['sources'] as $sourceUrl)<a href="{{ $sourceUrl }}" target="_blank" rel="noopener noreferrer">{{ parse_url($sourceUrl, PHP_URL_HOST) ?: $sourceUrl }} ↗</a>@endforeach</div>
                        </section>

                        <section id="positioning" class="pack-section">
                            <div class="section-header"><div><span class="section-label">POSITIONING</span><h3>{{ $content['positioning']['value_proposition'] }}</h3></div><span class="approved-status">Source constrained</span></div>
                            <p class="positioning-statement">{{ $content['positioning']['brand_position'] }}</p>
                            <div class="audience-table"><div class="audience-row audience-heading"><span>AUDIENCE</span><span>NEED</span><span>BUYER MOMENT</span><span>WHY RELEVANT</span></div>@foreach($content['positioning']['audience_priorities'] as $audience)<div class="audience-row"><strong>{{ $audience['name'] }}</strong><span>{{ $audience['need'] }}</span><span>{{ $audience['buyer_moment'] }}</span><span>{{ $audience['why_relevant'] }}</span></div>@endforeach</div>
                        </section>

                        <section id="ranked-angles" class="pack-section">
                            <div class="section-header"><div><span class="section-label">RANKED ANGLES</span><h3>Several routes explored. The strongest three lead.</h3></div><span class="count-badge">{{ count($content['ranked_angles']) }} ANGLES</span></div>
                            <div class="angle-table"><div class="angle-row angle-heading"><span>RANK</span><span>ANGLE</span><span>BUYER MOMENT</span><span>MAIN BENEFIT</span><span>STATUS</span></div>@foreach($content['ranked_angles'] as $angle)<article class="angle-row"><b>{{ str_pad($angle['rank'], 2, '0', STR_PAD_LEFT) }}</b><div><strong>{{ $angle['title'] }}</strong><p>{{ $angle['core_idea'] }}</p><small>{{ $angle['ranking_reason'] }}</small></div><span>{{ $angle['buyer_moment'] }}</span><span>{{ $angle['main_benefit'] }}</span><em class="angle-status {{ $angle['status'] }}">{{ $angle['status'] === 'recommended' ? 'Recommended' : 'Secondary' }}</em></article>@endforeach</div>
                        </section>

                        @push('campaign-banner-studio')
                        @php
                            $currentPackVersion = $pack->versions->firstWhere('version', $pack->current_version);
                            $bannerBatches = $pack->bannerGenerationBatches->sortBy('created_at');
                            $includedBannerBatch = $bannerBatches->firstWhere('kind', 'included');
                            $bannerCreatives = $bannerBatches->flatMap->creatives->sortBy('created_at');
                            $bannerActive = $bannerCreatives->contains(fn($creative) => in_array($creative->status, ['queued', 'processing', 'retrying']));
                            $includedComplete = $includedBannerBatch?->creatives->where('status', 'completed')->count() ?? 0;
                            $includedFailed = $includedBannerBatch?->creatives->where('status', 'failed')->count() ?? 0;
                        @endphp
                        <section id="banner-studio" @class(['pack-section', 'banner-studio', 'is-processing' => $bannerActive]) @if($bannerActive) wire:poll.3s @endif>
                            <div class="section-header banner-studio-header">
                                <div><span class="section-label">AI BANNER STUDIO</span><h3>Campaign-ready 4:5 creative</h3><p>Marketing Owl uses the first product image captured from the website, then applies your exact approved copy and branding.</p></div>
                                <span class="banner-format">PNG · 1080×1350</span>
                            </div>

                            @if(!$bannerStudioAvailable)
                                <div class="banner-locked"><span>◇</span><div><strong>Banner Studio is not enabled</strong><p>Enable the feature in campaign configuration before generating creative.</p></div></div>
                            @elseif($pack->status !== 'approved' || !$currentPackVersion || $currentPackVersion->review_status !== 'approved')
                                <div class="banner-locked"><span>⌁</span><div><strong>Approve the current version to unlock banners</strong><p>Banner copy is taken verbatim from the approved campaign pack, so draft and review versions stay locked.</p></div></div>
                            @else
                                <div class="banner-setup-grid">
                                    <div class="banner-setup-card">
                                        <div><span>01 · PRODUCT REFERENCE</span><strong>{{ $bannerProductImage ? 'Captured from product page' : 'No usable source image found' }}</strong><p>Banner generation automatically uses the first valid product image discovered while analyzing the website.</p></div>
                                        @if($bannerProductImage)
                                            <div class="banner-source-status"><span>WEBSITE IMAGE READY</span><strong>{{ $bannerProductImage->original_name }}</strong><small>{{ data_get($bannerProductImage->metadata, 'width') }}×{{ data_get($bannerProductImage->metadata, 'height') }} · {{ parse_url(data_get($bannerProductImage->metadata, 'source_url'), PHP_URL_HOST) ?: 'product source' }}</small></div>
                                        @else
                                            <div class="banner-source-status missing" role="status"><span>IMAGE NOT AVAILABLE</span><strong>The product page did not expose a usable JPEG, PNG, or WebP image.</strong><small>Update the product source URL before generating banners.</small></div>
                                        @endif
                                    </div>

                                    <form wire:submit="saveBannerBranding" class="banner-setup-card">
                                        <div><span>02 · BRAND OVERLAY</span><strong>{{ $pack->product->brand->banner_logo_path ? 'Logo saved' : 'Wordmark fallback' }}</strong><p>Logo and color are optional. Without a logo, the exact brand name is rendered as a neutral wordmark.</p></div>
                                        <div class="banner-brand-fields"><label>Logo <small>PNG or WebP</small><input wire:model="bannerLogo" type="file" accept="image/png,image/webp"></label><label>Primary color <input wire:model="bannerPrimaryColor" type="color" value="{{ $bannerPrimaryColor ?: '#F4B942' }}"></label></div>
                                        @error('bannerLogo')<small class="error">{{ $message }}</small>@enderror
                                        @error('bannerPrimaryColor')<small class="error">{{ $message }}</small>@enderror
                                        <button type="submit" wire:loading.attr="disabled" wire:target="saveBannerBranding">Save branding</button>
                                    </form>
                                </div>

                                @error('banner')<div class="banner-error" role="alert">{{ $message }}</div>@enderror
                                @if(!$includedBannerBatch)
                                    <div class="banner-launch">
                                        <div><span>INCLUDED WITH THIS PACK</span><h4>Three directions, generated automatically</h4><ol><li>Product hero and strongest verified benefit</li><li>Problem / solution using approved positioning</li><li>Lifestyle or usage context for the approved audience</li></ol></div>
                                        <button type="button" wire:click="generateIncludedBanners" wire:loading.attr="disabled" @disabled(!$bannerProductImage)><span wire:loading.remove wire:target="generateIncludedBanners">Generate 3 banners — included</span><span wire:loading wire:target="generateIncludedBanners">Preparing banners…</span></button>
                                    </div>
                                    @if(!$bannerProductImage)<p class="banner-requirement">A usable product-page image is required before generation.</p>@endif
                                @else
                                    <div class="banner-gallery" aria-live="polite">
                                        @foreach($bannerCreatives as $creative)
                                            <article class="banner-card" wire:key="banner-creative-{{ $creative->id }}-{{ $creative->status }}">
                                                <div class="banner-preview">
                                                    @if($creative->status === 'completed')
                                                        <img src="{{ route('campaign-banners.image', [$pack, $creative]) }}" alt="{{ $creative->direction }} banner">
                                                    @elseif($creative->status === 'failed')
                                                        <div class="banner-failed"><span>!</span><strong>Generation failed</strong><p>{{ $creative->error_message ?: 'The provider could not complete this banner.' }}</p></div>
                                                    @else
                                                        <div class="banner-working"><i></i><strong>{{ $creative->status === 'processing' ? 'Creating composition…' : 'Waiting to generate…' }}</strong><p>Attempt {{ min($creative->attempts + ($creative->status === 'queued' ? 1 : 0), config('campaigns.banners.retry_attempts')) }} of {{ config('campaigns.banners.retry_attempts') }}</p></div>
                                                    @endif
                                                </div>
                                                <div class="banner-card-body"><div><span>PACK V{{ $creative->campaignPackVersion->version }}</span><strong>{{ $creative->direction }}</strong><small>{{ str($creative->status)->replace('_', ' ')->title() }} · {{ $creative->headline }}</small></div>@if($creative->status === 'completed')<a href="{{ route('campaign-banners.download', [$pack, $creative]) }}">Download PNG ↓</a>@endif</div>
                                            </article>
                                        @endforeach
                                    </div>

                                    <div class="banner-actions">
                                        <div><strong>{{ $includedComplete }} / {{ config('campaigns.banners.included_count') }} included banners complete</strong><span>{{ $bannerActive ? 'One banner is processing. You can safely leave and return.' : 'Additional banners use the latest approved pack version.' }}</span></div>
                                        <div>
                                            @if($includedFailed > 0 && !$bannerActive)<button type="button" class="secondary-banner-action" wire:click="retryIncludedBanners">Retry {{ $includedFailed }} included {{ Str::plural('slot', $includedFailed) }} — free</button>@endif
                                            @if($includedComplete === config('campaigns.banners.included_count') && !$bannerActive)<button type="button" @click="bannerConfirm = true">Generate another · 1 credit</button>@endif
                                        </div>
                                    </div>

                                    <div class="banner-confirm" x-cloak x-show="bannerConfirm" @keydown.escape.window="bannerConfirm = false" role="dialog" aria-modal="true" aria-labelledby="banner-confirm-title">
                                        <div @click.outside="bannerConfirm = false"><span>ADDITIONAL BANNER</span><h4 id="banner-confirm-title">Use 1 workspace credit?</h4><p>One new 1080×1350 banner will be created from the latest approved version.</p><div><span>Current balance <strong>{{ $bannerCreditBalance }}</strong></span><i>→</i><span>Resulting balance <strong>{{ max(0, $bannerCreditBalance - 1) }}</strong></span></div>@if($bannerCreditBalance < 1)<small>There are not enough credits for another banner.</small>@endif<footer><button type="button" class="secondary-banner-action" @click="bannerConfirm = false">Cancel</button><button type="button" wire:click="generateAdditionalBanner" @click="bannerConfirm = false" @disabled($bannerCreditBalance < 1)>Confirm · 1 credit</button></footer></div>
                                    </div>
                                @endif

                                @if($bannerProcessUrl && $nextBannerCreative)
                                    <span hidden wire:key="banner-process-{{ $nextBannerCreative->id }}-{{ $nextBannerCreative->attempts }}"
                                        x-init="fetch(@js($bannerProcessUrl), { method: 'POST', headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content, 'Accept': 'application/json' } }).finally(() => $wire.$refresh())"></span>
                                @endif
                            @endif
                        </section>
                        @endpush

                        <section id="creative-routes" class="pack-section creative-routes-section">
                            <div class="section-header"><div><span class="section-label">CREATIVE ROUTES</span><h3>Three complete stories from the top angles</h3></div><button type="button" class="section-copy" @click="navigator.clipboard.writeText(@js(json_encode($content['creative_routes'], JSON_PRETTY_PRINT))); copied = 'routes'" x-text="copied === 'routes' ? '✓ Copied routes' : '▣ Copy all routes'"></button></div>
                            <div class="route-tabs" role="tablist" aria-label="Creative routes">@foreach($content['creative_routes'] as $routeIndex => $route)<button type="button" role="tab" @click="activeRoute = {{ $routeIndex }}" :aria-selected="(activeRoute === {{ $routeIndex }}).toString()" :class="activeRoute === {{ $routeIndex }} && 'active'">Route {{ $routeIndex + 1 }} · {{ $route['name'] }}</button>@endforeach</div>
                            @foreach($content['creative_routes'] as $routeIndex => $route)
                                <article class="creative-route" x-cloak x-show="activeRoute === {{ $routeIndex }}">
                                    <header><div><span>ANGLE #{{ $route['angle_rank'] }}</span><h4>{{ $route['name'] }}</h4><p>{{ $route['marketing_angle'] }}</p></div><button type="button" @click="navigator.clipboard.writeText(@js(json_encode($route, JSON_PRETTY_PRINT))); copied = 'route{{ $routeIndex }}'" x-text="copied === 'route{{ $routeIndex }}' ? '✓ Copied' : '▣ Copy route'"></button></header>
                                    <div class="route-brief"><div><small>TARGET BUYER</small><strong>{{ $route['target_buyer'] }}</strong></div><div><small>BUYER MOMENT</small><strong>{{ $route['buyer_moment'] }}</strong></div><div><small>CORE PROMISE</small><strong>{{ $route['core_promise'] }}</strong></div><div><small>TONE</small><strong>{{ $route['tone'] }}</strong></div></div>
                                    <div class="route-hooks"><p class="field-label">Hook options</p><div class="hook-grid">@foreach($route['hooks'] as $hookIndex => $hook)<div><b>0{{ $hookIndex + 1 }}</b><p>{{ $hook }}</p><button type="button" aria-label="Copy route {{ $routeIndex + 1 }} hook {{ $hookIndex + 1 }}" @click="navigator.clipboard.writeText(@js($hook))">▣</button></div>@endforeach</div></div>
                                    <div class="route-timing-grid">
                                        <div><div class="subsection-heading"><div><p class="field-label">Timed voiceover</p><span>{{ collect($route['voiceover'])->sum('word_count') }} words · {{ $route['total_duration_seconds'] }} sec</span></div><button type="button" aria-label="Copy route {{ $routeIndex + 1 }} voiceover" @click="navigator.clipboard.writeText(@js(collect($route['voiceover'])->pluck('line')->implode("\n")))">▣</button></div><div class="timed-table">@foreach($route['voiceover'] as $line)<div><time>{{ sprintf('%g', $line['start_seconds']) }}–{{ sprintf('%g', $line['end_seconds']) }}s</time><p>{{ $line['line'] }}<small>{{ $line['word_count'] }} words · {{ $line['pace_wpm'] }} wpm · {{ $line['delivery_notes'] }}</small></p><span>{{ $line['shot_id'] }}</span></div>@endforeach</div></div>
                                        <div><div class="subsection-heading"><div><p class="field-label">Timed captions</p><span>Sound-off sequence</span></div><button type="button" aria-label="Copy route {{ $routeIndex + 1 }} captions" @click="navigator.clipboard.writeText(@js(collect($route['captions'])->pluck('text')->implode("\n")))">▣</button></div><div class="timed-table">@foreach($route['captions'] as $caption)<div><time>{{ sprintf('%g', $caption['start_seconds']) }}–{{ sprintf('%g', $caption['end_seconds']) }}s</time><p>{{ $caption['text'] }}</p><span>{{ $caption['shot_id'] }}</span></div>@endforeach</div></div>
                                    </div>
                                </article>
                            @endforeach
                        </section>

                        <section id="shot-plans" class="pack-section">
                            <div class="section-header"><div><span class="section-label">SHOT PLANS</span><h3>What the team needs to record for each route</h3></div><span class="approved-status">No existing footage assumed</span></div>
                            @foreach($content['creative_routes'] as $routeIndex => $route)
                                <div class="route-panel" x-cloak x-show="activeRoute === {{ $routeIndex }}">
                                    <div class="subsection-heading"><div><strong>{{ $route['name'] }}</strong><span>{{ count($route['shot_plan']) }} planned shots</span></div><button type="button" aria-label="Copy {{ $route['name'] }} shot plan" @click="navigator.clipboard.writeText(@js(json_encode($route['shot_plan'], JSON_PRETTY_PRINT)))">▣ Copy shot plan</button></div>
                                    <div class="shot-plan-table">@foreach($route['shot_plan'] as $shot)<article><div class="shot-time"><time>{{ sprintf('%g', $shot['start_seconds']) }}–{{ sprintf('%g', $shot['end_seconds']) }}s</time><span class="shot-priority {{ $shot['priority'] }}">{{ $shot['priority'] }}</span></div><div class="shot-core"><strong>{{ ucfirst($shot['purpose']) }} · {{ $shot['scene'] }}</strong><p>{{ $shot['action'] }}</p><small>{{ $shot['camera_framing'] }} · {{ $shot['product_visibility'] }}</small></div><div><small>VOICEOVER</small><p>{{ $shot['voiceover_line'] }}</p><small>CAPTION</small><p>{{ $shot['on_screen_caption'] }}</p></div><div><small>FACT / BENEFIT</small><p>{{ $shot['product_fact_or_benefit'] }}</p><small>PRODUCTION</small><p>{{ $shot['props_or_requirements'] }} {{ $shot['lighting_or_movement'] }}</p></div></article>@endforeach</div>
                                </div>
                            @endforeach
                        </section>

                        <section id="platform-assets" class="pack-section">
                            <div class="section-header"><div><span class="section-label">PLATFORM ASSETS</span><h3>Publish-ready copy grouped under its route</h3></div><span class="approved-status">Reels · Shorts · WhatsApp · Meta</span></div>
                            @foreach($content['creative_routes'] as $routeIndex => $route)
                                <div class="platform-grid" x-cloak x-show="activeRoute === {{ $routeIndex }}">@foreach($route['platform_assets'] as $platform => $asset)<article><header><strong>{{ str($platform)->replace('_', ' ')->title() }}</strong><button type="button" aria-label="Copy {{ str($platform)->replace('_', ' ') }} assets" @click="navigator.clipboard.writeText(@js(json_encode($asset, JSON_PRETTY_PRINT)))">▣</button></header><small>PRIMARY COPY</small><p>{{ $asset['primary_copy'] }}</p><small>CAPTION / TITLE</small><p>{{ $asset['short_caption'] }}<br><strong>{{ $asset['title'] }}</strong></p><small>CTA</small><p>{{ $asset['cta'] }}</p>@if($asset['frames'])<small>FRAMES</small><ol>@foreach($asset['frames'] as $frame)<li>{{ $frame }}</li>@endforeach</ol>@endif</article>@endforeach</div>
                            @endforeach
                        </section>

                        @stack('campaign-banner-studio')

                        <section id="offers" class="pack-section">
                            <div class="section-header"><div><span class="section-label">OFFERS</span><h3>Value framing without invented discounts</h3></div><span class="approved-status">Source constrained</span></div>
                            <div class="offer-list">@forelse($content['offers'] as $offer)<article><div><strong>{{ $offer['wording'] }}</strong><p>{{ $offer['audience_or_situation'] }}</p></div><div><small>WHY IT FITS</small><p>{{ $offer['brand_fit'] }}</p></div><div><small>LIMITS</small><p>{{ $offer['limitations'] }}</p></div><button type="button" aria-label="Copy offer framing" @click="navigator.clipboard.writeText(@js($offer['wording']))">▣</button></article>@empty<p>No safe offer framing was generated.</p>@endforelse</div>
                        </section>

                        <section id="qa-approval" class="pack-section">
                            <div class="section-header"><div><span class="section-label">QA & APPROVAL</span><h3>{{ $qaIssues ? 'Approval is blocked until every issue is fixed.' : 'Claims, scripts, and captions pass deterministic checks.' }}</h3></div><span @class(['qa-status', 'passed' => !$qaIssues, 'blocked' => $qaIssues])>{{ $qaIssues ? count($qaIssues).' blocking '.Str::plural('issue', count($qaIssues)) : '✓ Ready for owner review' }}</span></div>
                            @if($qaIssues)<div class="qa-issues">@foreach($qaIssues as $issue)<article><span>{{ strtoupper($issue['type']) }}</span><p>{{ $issue['message'] }}</p></article>@endforeach</div>@else<div class="compliance-clear">✓ No unsupported, exaggerated, false-scarcity, or impossible-timing issues were detected.</div>@endif
                            <div class="evidence-list claim-classification">
                                @forelse($version->evidence ?? [] as $reference)
                                    <article><div><strong>{{ $reference['claim'] }}</strong><span class="claim-status {{ $reference['status'] }}">{{ str($reference['status'])->replace('_', ' ')->title() }}</span></div><blockquote>{{ $reference['excerpt'] ?? 'Source reference retained.' }}</blockquote><a href="{{ $reference['source'] }}" target="_blank" rel="noopener noreferrer">Open source ↗</a></article>
                                @empty
                                    <p>No claim evidence was returned for this version.</p>
                                @endforelse
                            </div>
                            @if($version->compliance_flags)<div class="compliance-flags"><h4>Compliance flags</h4>@foreach($version->compliance_flags as $flag)<article><strong>{{ ucfirst($flag['severity']) }} · {{ $flag['claim'] }}</strong><p>{{ $flag['reason'] }}</p></article>@endforeach</div>@endif
                        </section>

                        <section id="versions-exports" class="pack-section versions-export-section">
                            <div class="section-header"><div><span class="section-label">VERSIONS & EXPORTS</span><h3>Approved history and production sheets</h3></div><span class="approved-status">Current v{{ $version->version }} · {{ str($version->review_status)->title() }}</span></div>
                            <div class="version-list">@foreach($pack->versions->sortByDesc('version') as $packVersion)<button type="button" wire:click="selectVersion({{ $packVersion->version }})" @class(['active' => $packVersion->version === $version->version])><span>v{{ $packVersion->version }}</span><strong>{{ str($packVersion->review_status)->title() }}</strong><small>{{ $packVersion->created_at->format('M j, Y') }}</small>@if($packVersion->review_status === 'approved')<em>✓ Approved version</em>@endif</button>@endforeach</div>
                            <div class="workspace-exports"><button type="button" @click="navigator.clipboard.writeText(@js(json_encode($content, JSON_PRETTY_PRINT)))">▣ Copy complete Campaign Pack</button>@if($version->review_status === 'approved')<a href="{{ route('campaign-packs.export', [$pack, $version, 'pdf']) }}">Complete pack</a><a href="{{ route('campaign-packs.export', [$pack, $version, 'voiceover']) }}">Voiceover sheet</a><a href="{{ route('campaign-packs.export', [$pack, $version, 'captions']) }}">Timed caption sheet</a><a href="{{ route('campaign-packs.export', [$pack, $version, 'shot-plan']) }}">Shot plan</a>@else<span>Approve this version to unlock file exports.</span>@endif</div>
                        </section>
                    </article>
                </div>
            </div>
            @endif
        @endif
    </main>
</div>
