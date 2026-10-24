{{--
    The client hub: approved work for one product, laid out for handoff.
    Rendered in the Client hub tab (as a preview), on the shared client link and in the client portal.
    Expects $product and $hub (App\Support\ClientHub::build).
--}}
@php
    $content = $hub['content'];
    $version = $hub['version'];
    $channels = $hub['channels'];
    $firstChannel = array_key_first($channels);
    $firstGoogle = array_key_first($channels['google_ads']['types'] ?? []) ?? 'search';
    $approvedAt = $version ? ($version->reviewed_at ?? $version->updated_at) : null;
    $value = trim((string) data_get($content, 'key_messaging.value_proposition'));
    $tone = trim((string) data_get($content, 'key_messaging.tone'));
    $audiences = array_values(array_filter((array) data_get($content, 'key_messaging.audiences', []), 'filled'));
    $proof = array_values(array_filter((array) data_get($content, 'key_messaging.proof_points', []), fn ($item) => is_string($item) && filled($item)));
    $facts = array_values(array_filter((array) data_get($content, 'product_details.facts', []), fn ($item) => is_string($item) && filled($item)));
    $sourceUrl = data_get($content, 'product_details.source_url');
    $price = data_get($content, 'product_details.price');
    $summary = data_get($content, 'product_details.summary') ?: data_get($content, 'overview.summary');
    $resourceIcons = ['product_page' => 'external-link', 'brand_guide' => 'book', 'master_drive' => 'folder', 'meta_drive' => 'brand-meta', 'google_ads_drive' => 'brand-google-drive', 'email_sms_drive' => 'mail', 'organic_social_drive' => 'social'];
@endphp

<div class="hub col" x-data="{
        channel: @js($firstChannel), gtype: @js($firstGoogle), copied: null,
        async copy(text, key) {
            try { await navigator.clipboard.writeText(text) } catch (e) {
                const area = document.createElement('textarea'); area.value = text; document.body.appendChild(area); area.select(); document.execCommand('copy'); area.remove();
            }
            this.copied = key; setTimeout(() => { if (this.copied === key) this.copied = null }, 1600);
        }
    }">
    <section class="card hub-intro" aria-labelledby="hub-product-name">
        <div class="hub-photo" data-initials="{{ mb_strtoupper(mb_substr($product->name, 0, 1)) }}">
            @if($hub['imageUrl'])<img src="{{ $hub['imageUrl'] }}" alt="{{ $product->name }}" onerror="this.remove()">@endif
        </div>
        <div class="col grow hub-intro-text">
            <div class="row hub-meta">
                <span class="tag ok"><span class="dot"></span>Approved{{ $version ? ' · version '.$version->version : '' }}</span>
                @if($approvedAt)<span class="muted xs">Approved {{ $approvedAt->format('j M Y') }}</span>@endif
            </div>
            <{{ $heading ?? 'h2' }} id="hub-product-name" class="display hub-name">{{ $product->name }}</{{ $heading ?? 'h2' }}>
            <p class="muted small">{{ $product->brand->name }}@if($price && $price !== 'Price not supplied') · {{ $price }}@endif</p>
            @if($summary)<p class="hub-summary">{{ $summary }}</p>@endif
            @if($hub['links']->isNotEmpty())
                <div class="row hub-links">
                    @foreach($hub['links'] as $link)
                        <a class="pill ghost sm" href="{{ $link->url }}" target="_blank" rel="noopener"><i class="ti ti-{{ $resourceIcons[$link->kind] ?? 'link' }}" aria-hidden="true"></i>{{ $link->label }}</a>
                    @endforeach
                </div>
            @endif
        </div>
    </section>

    <nav class="chips hub-jump" aria-label="Jump to">
        @if($value || $proof)<a class="chip" href="#hub-message">Key message</a>@endif
        @if($channels)<a class="chip" href="#hub-copy">Ready-to-use copy</a>@endif
        @if($hub['images'])<a class="chip" href="#hub-images">Images · {{ count($hub['images']) }}</a>@endif
        @if($facts)<a class="chip" href="#hub-facts">Product facts</a>@endif
    </nav>

    <div class="grid">
        @if($value || $proof || $audiences)
            <section id="hub-message" class="card col c7" aria-labelledby="hub-message-title">
                <h3 id="hub-message-title" class="eyebrow">Key message</h3>
                @if($value)
                    <p class="display hub-quote">{{ $value }}</p>
                @endif
                @if($tone)<p class="small"><span class="muted">Tone of voice:</span> {{ $tone }}</p>@endif
                @if($audiences)
                    <div class="col hub-tight"><span class="muted small">Who it is for</span><div class="row hub-tags">@foreach($audiences as $audience)<span class="tag">{{ $audience }}</span>@endforeach</div></div>
                @endif
                @if($proof)
                    <div class="col hub-tight">
                        <span class="muted small">Proof points you can use</span>
                        @foreach($proof as $index => $line)
                            @include('client-hub.copy-row', ['text' => $line, 'key' => 'proof-'.$index, 'limit' => null, 'hard' => false])
                        @endforeach
                    </div>
                @endif
            </section>
        @endif

        @if($facts || $sourceUrl)
            <section id="hub-facts" class="card col c5" aria-labelledby="hub-facts-title">
                <h3 id="hub-facts-title" class="eyebrow">Product facts</h3>
                <p class="muted small">Checked against the product page. All copy in this hub is limited to these facts.</p>
                @foreach($facts as $index => $fact)
                    @include('client-hub.copy-row', ['text' => $fact, 'key' => 'fact-'.$index, 'limit' => null, 'hard' => false])
                @endforeach
                @if($sourceUrl)<a class="small" href="{{ $sourceUrl }}" target="_blank" rel="noopener">Open the product page <i class="ti ti-external-link" aria-hidden="true"></i></a>@endif
            </section>
        @endif

        @if($channels)
            <section id="hub-copy" class="card col c12" aria-labelledby="hub-copy-title">
                <div class="row">
                    <div class="grow"><h3 id="hub-copy-title" class="eyebrow">Ready-to-use copy</h3><p class="muted small">Approved lines for each channel. Copy a line, or download Google Ads copy as a CSV for bulk upload.</p></div>
                </div>
                <div class="dtabs" role="tablist" aria-label="Channel">
                    @foreach($channels as $key => $channel)
                        <button type="button" role="tab" id="hub-tab-{{ $key }}" aria-controls="hub-panel-{{ $key }}" :aria-selected="(channel === '{{ $key }}').toString()" aria-selected="{{ $key === $firstChannel ? 'true' : 'false' }}" @click="channel = '{{ $key }}'"><i class="ti ti-{{ $channel['icon'] }}" aria-hidden="true"></i> {{ $channel['label'] }}</button>
                    @endforeach
                </div>
                @foreach($channels as $key => $channel)
                    <div class="col hub-panel" role="tabpanel" id="hub-panel-{{ $key }}" aria-labelledby="hub-tab-{{ $key }}" x-show="channel === '{{ $key }}'" @if($key !== $firstChannel) x-cloak @endif>
                        @if($key === 'google_ads')
                            <div class="row">
                                <div class="chips grow" role="group" aria-label="Google Ads campaign type">
                                    @foreach($channel['types'] as $type => $details)
                                        <button type="button" class="chip" :aria-pressed="(gtype === '{{ $type }}').toString()" aria-pressed="{{ $type === $firstGoogle ? 'true' : 'false' }}" @click="gtype = '{{ $type }}'">{{ $details['label'] }}</button>
                                    @endforeach
                                </div>
                                @foreach($channel['types'] as $type => $details)
                                    <a class="pill ghost sm" href="{{ ($hub['exportUrl'])($type) }}" x-show="gtype === '{{ $type }}'" @if($type !== $firstGoogle) x-cloak @endif><i class="ti ti-download" aria-hidden="true"></i>Download {{ $details['label'] }} CSV</a>
                                @endforeach
                            </div>
                            @foreach($channel['types'] as $type => $details)
                                <div class="col hub-fields" x-show="gtype === '{{ $type }}'" @if($type !== $firstGoogle) x-cloak @endif>
                                    @include('client-hub.fields', ['fields' => $details['fields'], 'prefix' => 'google-'.$type])
                                    @if($details['final_url'])
                                        <div class="kv"><span>Landing page</span><span><a href="{{ $details['final_url'] }}" target="_blank" rel="noopener" class="break">{{ $details['final_url'] }}</a></span></div>
                                    @endif
                                    @if($details['promotion'])<div class="kv"><span>Promotion</span><span>{{ $details['promotion'] }}</span></div>@endif
                                </div>
                            @endforeach
                        @else
                            @include('client-hub.fields', ['fields' => $channel['fields'], 'prefix' => $key])
                        @endif
                    </div>
                @endforeach
            </section>
        @endif

        @if($hub['images'])
            <section id="hub-images" class="card col c12" aria-labelledby="hub-images-title">
                <h3 id="hub-images-title" class="eyebrow">Images · {{ count($hub['images']) }}</h3>
                <div class="hub-images">
                    @foreach($hub['images'] as $image)
                        <figure class="hub-image">
                            <img src="{{ $image['url'] }}" alt="{{ $image['headline'] ? 'Banner: '.$image['headline'] : 'Approved banner' }}" loading="lazy">
                            <figcaption class="row"><span class="grow small">{{ $image['headline'] ?: 'Banner' }}</span><a class="pill ghost sm" href="{{ $image['url'] }}" download="{{ \Illuminate\Support\Str::slug($product->name) }}-banner-{{ $image['id'] }}.png"><i class="ti ti-download" aria-hidden="true"></i>Download</a></figcaption>
                        </figure>
                    @endforeach
                </div>
            </section>
        @endif
    </div>
</div>
