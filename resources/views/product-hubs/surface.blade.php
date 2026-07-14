@php
    $resourceLinks = $product->resourceLinks->keyBy('kind');
    $image = $product->mediaAssets->first(fn ($asset) => str_starts_with($asset->mime_type, 'image/') && $asset->status !== 'failed');
    $mediaUrl = $image ? ($isPublic ? route('product-hubs.shared-media', [$shareToken, $image]) : route('products.hub-media', [$product, $image])) : null;
    $exportBase = fn (string $type) => $isPublic ? route('product-hubs.shared-export', [$shareToken, $type]) : route('products.hub-export', [$product, $type]);
    $google = $content ? data_get($content, 'channels.google_ads', []) : [];
    $creatives = $version ? $version->bannerCreatives->where('status', 'completed')->filter(fn ($creative) => filled($creative->output_path))->take(3) : collect();
@endphp

@unless($isPublic)
<button class="hub-mobile-trigger" type="button" @click="mobileNav = true" aria-label="Open main navigation"><i class="ti ti-menu-2"></i></button>
@endunless

<aside class="product-rail" :class="mobileNav && 'is-open'" aria-label="Product hub navigation">
    <button class="product-rail-close" type="button" @click="mobileNav = false" aria-label="Close product navigation"><i class="ti ti-x"></i></button>
    @if($isPublic)
        <a class="hub-brand" href="#"><img src="/marketing-owl-logo.png" alt=""><span>Marketing<br>Owl</span></a>
    @endif
    <div class="product-rail-heading">
        <a href="{{ $isPublic ? '#' : route('products.index') }}"><i class="ti ti-arrow-left"></i> Products</a>
        <p>Product playbook</p>
        <h2>{{ $product->name }}</h2>
        <span>{{ $product->brand->name }}</span>
    </div>
    <nav>
        @foreach($sections as $key => $label)
            <button type="button" @click="section = '{{ $key }}'; mobileNav = false" :class="section === '{{ $key }}' && 'active'" :aria-current="section === '{{ $key }}' ? 'page' : null">
                <i class="ti {{ match($key) { 'overview' => 'ti-layout-dashboard', 'product_details' => 'ti-package', 'key_messaging' => 'ti-message-circle', 'meta_ads' => 'ti-brand-meta', 'google_ads' => 'ti-brand-google', 'email_sms' => 'ti-mail', 'organic_social' => 'ti-social', 'asset_links' => 'ti-link', default => 'ti-history' } }}"></i>
                {{ $label }}
            </button>
        @endforeach
    </nav>
    @unless($isPublic)
        <a class="internal-workspace-link" href="{{ $version ? route('campaign-packs.show', $version->campaignPack) : route('campaign-packs.create') }}"><i class="ti ti-settings"></i> Internal campaign workspace</a>
    @endunless
    @if(!$isPublic && $canManage && $version)
        <button type="button" class="rail-share-button" wire:click="createShare"><i class="ti ti-share"></i> {{ $shareUrl ? 'Refresh client link' : 'Share this hub' }}</button>
    @endif
    @if($isPublic)<p class="read-only-note"><i class="ti ti-lock"></i> Read-only client view</p>@endif
</aside>

<main class="product-hub-main" id="product-hub-content">
    <header class="hub-topbar">
        <div>
            <span class="hub-breadcrumb"><a href="{{ $isPublic ? '#' : route('products.index') }}">Products</a><i class="ti ti-chevron-right"></i><span>{{ $product->name }}</span><i class="ti ti-chevron-right"></i><strong x-text="({ overview: 'Overview', product_details: 'Product details', key_messaging: 'Key messaging', meta_ads: 'Meta Ads', google_ads: 'Google Ads', email_sms: 'Email & SMS', organic_social: 'Organic social', asset_links: 'Asset links', campaign_history: 'Campaign history' })[section]"></strong></span>
        </div>
        <div class="hub-actions">
            @unless($isPublic)
                @if($canManage)
                    @if($shareUrl)
                        <button type="button" wire:click="revokeShare" class="quiet-button"><i class="ti ti-link-off"></i> Revoke link</button>
                        <button type="button" @click="copy(@js($shareUrl), 'share')" class="primary-button"><i class="ti ti-share"></i> <span x-text="copied === 'share' ? 'Copied' : 'Copy client link'"></span></button>
                    @elseif($version)
                        <button type="button" wire:click="createShare" class="primary-button"><i class="ti ti-share"></i> Share with client</button>
                    @endif
                @endif
            @endunless
            @if($version)<span class="approved-pill"><i class="ti ti-circle-check-filled"></i> Approved v{{ $version->version }}</span>@endif
        </div>
    </header>

    @if(!$version || !$content)
        <section class="hub-empty">
            <i class="ti ti-file-off"></i>
            <p>CLIENT HUB</p>
            <h1>No approved marketing pack yet</h1>
            <span>This hub will publish automatically when a campaign-pack version is approved.</span>
            @unless($isPublic)<a href="{{ route('campaign-packs.create') }}">Open campaign workspace <i class="ti ti-arrow-right"></i></a>@endunless
        </section>
    @else
        <section class="hub-product-intro">
            <div class="intro-media">@if($mediaUrl)<img src="{{ $mediaUrl }}" alt="{{ $product->name }}">@else<i class="ti ti-photo-off"></i>@endif</div>
            <div class="intro-copy"><h1>{{ $product->name }}</h1><p>{{ $product->brand->name }} <span>•</span> {{ data_get($content, 'product_details.price') }}</p><small>{{ data_get($content, 'overview.summary') }}</small><em>Copy individual fields or export all Google Ads fields as CSV.</em></div>
            <div class="intro-tools">
                @foreach(['search','performance_max','display'] as $type)<a x-show="section === 'google_ads' && campaign === '{{ $type }}'" href="{{ $exportBase($type) }}" class="export-button"><i class="ti ti-download"></i> Export CSV</a>@endforeach
                <div>
                    @foreach(['product_page' => ['Product page','ti-external-link'], 'brand_guide' => ['Brand guide','ti-book'], 'master_drive' => ['Master Drive folder','ti-folder']] as $kind => $tool)
                        @if($resourceLinks->has($kind))<a href="{{ $resourceLinks[$kind]->url }}" target="_blank" rel="noopener"><i class="ti {{ $tool[1] }}"></i><span>{{ $tool[0] }}</span></a>@else<span class="disabled"><i class="ti {{ $tool[1] }}"></i><span>{{ $tool[0] }}</span></span>@endif
                    @endforeach
                </div>
            </div>
        </section>
        <section class="hub-section" x-show="section === 'overview'" x-cloak>
            <div class="hub-title"><p>PLAYBOOK OVERVIEW</p><h1>Your product marketing, ready to use.</h1><span>The latest approved messaging and channel assets in one client-ready hub.</span></div>
            <div class="overview-grid">
                <article class="overview-summary"><div>@if($mediaUrl)<img src="{{ $mediaUrl }}" alt="">@else<i class="ti ti-photo-off"></i>@endif</div><section><p>PRODUCT</p><h2>{{ data_get($content, 'product_details.name') }}</h2><span>{{ data_get($content, 'overview.summary') }}</span><dl><div><dt>Price</dt><dd>{{ data_get($content, 'product_details.price') }}</dd></div><div><dt>Last approved</dt><dd>{{ data_get($content, 'overview.updated_at') }}</dd></div></dl></section></article>
                <article class="message-card"><p>CORE MESSAGE</p><blockquote>{{ data_get($content, 'key_messaging.value_proposition') }}</blockquote><span>{{ data_get($content, 'key_messaging.tone') }}</span></article>
            </div>
            <div class="channel-launcher">
                @foreach(['meta_ads' => 'Meta Ads', 'google_ads' => 'Google Ads', 'email_sms' => 'Email & SMS', 'organic_social' => 'Organic social'] as $key => $label)
                    <button type="button" @click="section = '{{ $key }}'"><i class="ti {{ match($key) { 'meta_ads' => 'ti-brand-meta', 'google_ads' => 'ti-brand-google', 'email_sms' => 'ti-mail', default => 'ti-social' } }}"></i><span><strong>{{ $label }}</strong><small>Approved copy and handoff fields</small></span><i class="ti ti-arrow-right"></i></button>
                @endforeach
            </div>
        </section>

        <section class="hub-section" x-show="section === 'product_details'" x-cloak>
            <div class="hub-title"><p>PRODUCT DETAILS</p><h1>The source-linked product truth.</h1><span>Approved details that channel messaging can safely rely on.</span></div>
            <div class="detail-card"><dl><div><dt>Product</dt><dd>{{ data_get($content, 'product_details.name') }}</dd></div><div><dt>Price</dt><dd>{{ data_get($content, 'product_details.price') }}</dd></div><div><dt>Brand</dt><dd>{{ $product->brand->name }}</dd></div></dl><p>{{ data_get($content, 'product_details.summary') }}</p>@if(data_get($content, 'product_details.source_url'))<a href="{{ data_get($content, 'product_details.source_url') }}" target="_blank" rel="noopener">Open source product page <i class="ti ti-external-link"></i></a>@endif</div>
            <div class="field-stack"><h2>Verified facts</h2>@forelse(data_get($content, 'product_details.facts', []) as $index => $fact)<article><span>{{ $fact }}</span><button type="button" @click="copy(@js($fact), 'fact-{{ $index }}')" aria-label="Copy verified fact {{ $index + 1 }}"><i class="ti" :class="copied === 'fact-{{ $index }}' ? 'ti-check' : 'ti-copy'"></i><span x-text="copied === 'fact-{{ $index }}' ? 'Copied' : 'Copy'"></span></button></article>@empty<p class="inline-empty">No verified facts were supplied.</p>@endforelse</div>
        </section>

        <section class="hub-section" x-show="section === 'key_messaging'" x-cloak>
            <div class="hub-title"><p>KEY MESSAGING</p><h1>The words that hold the story together.</h1><span>Use these approved foundations across every channel.</span></div>
            <div class="message-foundation"><article><p>VALUE PROPOSITION</p><h2>{{ data_get($content, 'key_messaging.value_proposition') }}</h2></article><article><p>TONE</p><h2>{{ data_get($content, 'key_messaging.tone') }}</h2></article></div>
            @foreach(['audiences' => 'Priority audiences', 'proof_points' => 'Proof points'] as $field => $label)<div class="field-stack"><h2>{{ $label }}</h2>@forelse(data_get($content, 'key_messaging.'.$field, []) as $index => $value)<article><span>{{ $value }}</span><button type="button" @click="copy(@js($value), '{{ $field }}-{{ $index }}')" aria-label="Copy {{ strtolower($label) }} {{ $index + 1 }}"><i class="ti" :class="copied === '{{ $field }}-{{ $index }}' ? 'ti-check' : 'ti-copy'"></i><span x-text="copied === '{{ $field }}-{{ $index }}' ? 'Copied' : 'Copy'"></span></button></article>@empty<p class="inline-empty">No approved {{ strtolower($label) }} yet.</p>@endforelse</div>@endforeach
        </section>

        <section class="hub-section" x-show="section === 'meta_ads'" x-cloak>
            <div class="hub-title"><p>META ADS</p><h1>Copy built for the feed.</h1><span>Approved primary text, headlines, descriptions, and calls to action.</span></div>
            @foreach(['primary_texts' => 'Primary text', 'headlines' => 'Headlines', 'descriptions' => 'Descriptions', 'ctas' => 'Calls to action'] as $field => $label)
                <div class="field-stack"><h2>{{ $label }}</h2>@forelse(data_get($content, 'channels.meta_ads.'.$field, []) as $index => $value)<article><span>{{ $value }}</span><small>{{ mb_strlen($value) }} characters</small><button type="button" @click="copy(@js($value), 'meta-{{ $field }}-{{ $index }}')" aria-label="Copy {{ strtolower($label) }} {{ $index + 1 }}"><i class="ti" :class="copied === 'meta-{{ $field }}-{{ $index }}' ? 'ti-check' : 'ti-copy'"></i><span x-text="copied === 'meta-{{ $field }}-{{ $index }}' ? 'Copied' : 'Copy'"></span></button></article>@empty<p class="inline-empty">No approved {{ strtolower($label) }} yet.</p>@endforelse</div>
            @endforeach
        </section>

        <section class="hub-section google-section" x-show="section === 'google_ads'" x-cloak>
            <div class="hub-title with-action"><div><p>GOOGLE ADS</p><h1>Google Ads copy</h1><span>Use the approved copy below in your Google Ads account. Every field is ready for handoff.</span></div><div>@foreach(['search','performance_max','display'] as $type)<a x-show="campaign === '{{ $type }}'" href="{{ $exportBase($type) }}" class="export-button"><i class="ti ti-file-type-csv"></i> Export CSV</a>@endforeach</div></div>
            <div class="campaign-tabs" role="tablist" aria-label="Google Ads campaign type">
                @foreach(['search' => 'Search campaign', 'performance_max' => 'Performance Max', 'display' => 'Display'] as $key => $label)<button type="button" role="tab" @click="campaign = '{{ $key }}'" :aria-selected="(campaign === '{{ $key }}').toString()" :class="campaign === '{{ $key }}' && 'active'">{{ $label }}</button>@endforeach
            </div>
            @foreach(['search','performance_max','display'] as $type)
                <div class="google-layout" x-show="campaign === '{{ $type }}'" x-cloak>
                    <div>
                        @foreach(['headlines' => 'Headlines', 'long_headlines' => 'Long headlines', 'descriptions' => 'Descriptions'] as $field => $label)
                            <div class="field-stack"><h2>{{ $label }} <small>{{ count(data_get($google, $type.'.'.$field, [])) }}</small></h2>@forelse(data_get($google, $type.'.'.$field, []) as $index => $value)<article><span>{{ $value }}</span><small>{{ mb_strlen($value) }} characters</small><button type="button" @click="copy(@js($value), 'google-{{ $type }}-{{ $field }}-{{ $index }}')" aria-label="Copy {{ strtolower($label) }} {{ $index + 1 }}"><i class="ti" :class="copied === 'google-{{ $type }}-{{ $field }}-{{ $index }}' ? 'ti-check' : 'ti-copy'"></i><span x-text="copied === 'google-{{ $type }}-{{ $field }}-{{ $index }}' ? 'Copied' : 'Copy'"></span></button></article>@empty<p class="inline-empty">No approved {{ strtolower($label) }} yet.</p>@endforelse</div>
                        @endforeach
                    </div>
                    <div class="google-side">
                        <article class="ad-preview"><p>AD PREVIEW</p><small>Sponsored</small><span>{{ parse_url(data_get($google, $type.'.final_url', ''), PHP_URL_HOST) ?: $product->brand->name }}</span><h3>{{ data_get($google, $type.'.headlines.0', $product->name) }}</h3><p>{{ data_get($google, $type.'.descriptions.0', data_get($content, 'key_messaging.value_proposition')) }}</p></article>
                        <article class="linked-assets"><p>LINKED ASSETS</p>@if($creatives->isNotEmpty())<div class="creative-thumbnails">@foreach($creatives as $creative)<img src="{{ $isPublic ? route('product-hubs.shared-creative', [$shareToken, $creative]) : route('products.hub-creative', [$product, $creative]) }}" alt="Approved campaign creative">@endforeach</div>@endif @if($resourceLinks->has('google_ads_drive'))<a href="{{ $resourceLinks['google_ads_drive']->url }}" target="_blank" rel="noopener"><i class="ti ti-brand-google-drive"></i><span><strong>Google Ads folder</strong><small>Open approved creative assets</small></span><i class="ti ti-external-link"></i></a>@elseif($creatives->isEmpty())<div class="asset-empty"><i class="ti ti-folder-off"></i><span>No approved assets or Google Ads folder linked.</span></div>@endif</article>
                    </div>
                </div>
                <div class="handoff-strip" x-show="campaign === '{{ $type }}'" x-cloak><div><span>Final URL</span><a href="{{ data_get($google, $type.'.final_url', '#') }}" target="_blank" rel="noopener">{{ data_get($google, $type.'.final_url', 'Not supplied') }}</a></div><div><span>Sitelinks</span><strong>{{ count(data_get($google, $type.'.sitelinks', [])) }} supplied</strong></div><div><span>Promotion</span><strong>{{ data_get($google, $type.'.promotion') ?: 'No promotion supplied' }}</strong></div></div>
            @endforeach
        </section>

        @foreach(['email_sms' => ['EMAIL & SMS', 'Messages ready for the inbox.', ['subject_lines' => 'Subject lines', 'preview_texts' => 'Preview text', 'email_bodies' => 'Email body', 'sms_messages' => 'SMS messages']], 'organic_social' => ['ORGANIC SOCIAL', 'Keep the product story moving.', ['captions' => 'Captions', 'hooks' => 'Hooks', 'hashtags' => 'Hashtags']]] as $sectionKey => $details)
            <section class="hub-section" x-show="section === '{{ $sectionKey }}'" x-cloak><div class="hub-title"><p>{{ $details[0] }}</p><h1>{{ $details[1] }}</h1><span>Approved channel copy, ready for individual use.</span></div>@foreach($details[2] as $field => $label)<div class="field-stack"><h2>{{ $label }}</h2>@forelse(data_get($content, 'channels.'.$sectionKey.'.'.$field, []) as $index => $value)<article><span>{{ $value }}</span><small>{{ mb_strlen($value) }} characters</small><button type="button" @click="copy(@js($value), '{{ $sectionKey }}-{{ $field }}-{{ $index }}')" aria-label="Copy {{ strtolower($label) }} {{ $index + 1 }}"><i class="ti" :class="copied === '{{ $sectionKey }}-{{ $field }}-{{ $index }}' ? 'ti-check' : 'ti-copy'"></i><span x-text="copied === '{{ $sectionKey }}-{{ $field }}-{{ $index }}' ? 'Copied' : 'Copy'"></span></button></article>@empty<p class="inline-empty">No approved {{ strtolower($label) }} yet.</p>@endforelse</div>@endforeach</section>
        @endforeach

        <section class="hub-section" x-show="section === 'asset_links'" x-cloak>
            <div class="hub-title"><p>ASSET LINKS</p><h1>Every approved resource, one click away.</h1><span>Links open their real source; folders are not represented as synchronized.</span></div>
            <div class="resource-grid">@forelse($product->resourceLinks as $link)<a href="{{ $link->url }}" target="_blank" rel="noopener"><i class="ti {{ str_contains($link->kind, 'drive') ? 'ti-brand-google-drive' : 'ti-link' }}"></i><span><strong>{{ $link->label }}</strong><small>{{ parse_url($link->url, PHP_URL_HOST) }}</small></span><i class="ti ti-external-link"></i></a>@empty<div class="resource-empty"><i class="ti ti-folder-off"></i><h2>No resources linked</h2><p>Add product pages, brand guides, or Drive folders below.</p></div>@endforelse</div>
            @if(!$isPublic && $canManage)<form wire:submit="saveResources" class="resource-form"><h2>Manage resource links</h2>@if(session('resource-status'))<p class="success-message">{{ session('resource-status') }}</p>@endif<div>@foreach($resourceLabels as $kind => $label)<label><span>{{ $label }}</span><input type="url" wire:model="resourceInputs.{{ $kind }}" placeholder="https://"><small>@error('resourceInputs.'.$kind){{ $message }}@enderror</small></label>@endforeach</div><button type="submit" class="primary-button"><i class="ti ti-device-floppy"></i> Save links</button></form>@endif
        </section>

        <section class="hub-section" x-show="section === 'campaign_history'" x-cloak>
            <div class="hub-title"><p>CAMPAIGN HISTORY</p><h1>What has been approved and when.</h1><span>Draft versions stay inside the agency workspace.</span></div>
            <div class="history-list">@forelse($product->campaignPacks as $pack)@foreach($pack->versions->where('review_status', 'approved')->sortByDesc('reviewed_at') as $approved)<article><i class="ti ti-circle-check-filled"></i><div><strong>{{ $pack->name }}</strong><span>Version {{ $approved->version }} · Approved {{ optional($approved->reviewed_at)->format('M j, Y') ?: 'date unavailable' }}</span></div>@unless($isPublic)<a href="{{ route('campaign-packs.show', $pack) }}">Open workspace <i class="ti ti-arrow-right"></i></a>@endunless</article>@endforeach@empty<p class="inline-empty">No approved campaign history yet.</p>@endforelse</div>
        </section>
    @endif
</main>
