<div class="camp-tab">
    @if($flash)<div class="banner ok" role="status"><i class="ti ti-circle-check" aria-hidden="true"></i><span>{{ $flash }}</span></div>@endif
    @foreach(['launch', 'proposal'] as $key)
        @error($key)<div class="banner bad" role="alert"><i class="ti ti-alert-triangle" aria-hidden="true"></i><span>{{ $message }}</span></div>@enderror
    @endforeach

    @if($mode === 'results')
        <div class="grid">
            @forelse($campaigns as $item)
                @php [$tone, $label] = \App\Models\Campaign::STATUSES[$item->status]; @endphp
                <section class="card col c6" wire:key="results-{{ $item->id }}">
                    <div class="row"><span class="grow"><strong class="medium">{{ $item->name }}</strong>@if($item->dateRange()) <span class="muted small">· {{ $item->dateRange() }}</span>@endif</span><span class="tag {{ $tone }}"><span class="dot"></span>{{ $label }}</span></div>
                    @include('campaigns.results-card', ['campaign' => $item, 'summary' => $summaries[$item->id], 'orderStore' => $orderStores[$item->id] ?? null])
                    <a class="pill ghost sm self-start" href="{{ route('products.show', [$product, 'tab' => 'campaigns', 'campaign' => $item->id]) }}" wire:navigate>Open campaign</a>
                </section>
            @empty
                <div class="empty c12"><strong>No results yet</strong><span class="muted small">Results belong to campaigns. Plan one to start measuring.</span><button type="button" class="pill ghost sm" wire:click="plan">Plan a campaign</button></div>
            @endforelse
            <section class="card col c6">
                <span class="eyebrow">AI generation usage · kept separate</span>
                <p class="small">Generating content for {{ $product->name }} has used <strong class="num medium">{{ number_format($creditsUsed) }} pack {{ str('credit')->plural($creditsUsed) }}</strong> across {{ $packCount }} launch {{ str('pack')->plural($packCount) }}. Usage is a production cost and is never mixed into marketing results.</p>
                <a class="small" href="{{ route('usage.index') }}" wire:navigate>Usage</a>
            </section>
        </div>
    @elseif(! $campaign)
        <div class="empty">
            <strong>No campaigns yet</strong>
            <span class="muted small">A campaign ties an objective, audience and dates to exact approved versions of your content and page. Launching pins those versions; it does not start any ad spend.</span>
            <button type="button" class="pill pri sm" wire:click="plan" wire:loading.attr="disabled">Plan a campaign</button>
        </div>
    @else
        @php
            [$tone, $label] = \App\Models\Campaign::STATUSES[$campaign->status];
            $canLaunch = collect($readiness)->every(fn ($check) => $check['ok']);
            $page = $campaign->page;
        @endphp
        <div class="row camp-switch">
            <div class="chips" role="group" aria-label="Campaigns">
                @foreach($campaigns as $option)
                    <button type="button" class="chip" aria-pressed="{{ $option->id === $campaign->id ? 'true' : 'false' }}" wire:click="show({{ $option->id }})">{{ $option->name }}</button>
                @endforeach
            </div>
            <button type="button" class="linkbtn" wire:click="plan"><i class="ti ti-plus" aria-hidden="true"></i> Plan a campaign</button>
        </div>

        <div class="grid">
            @if($editing)
                <form class="card col c8" wire:submit="save">
                    <div class="row"><span class="eyebrow grow">Edit plan</span><span class="tag {{ $tone }}"><span class="dot"></span>{{ $label }}</span></div>
                    <div class="camp-form">
                        <div class="field wide"><label for="camp-name">Name</label><input id="camp-name" class="input" wire:model="form.name" maxlength="120">@error('form.name')<span class="field-error">{{ $message }}</span>@enderror</div>
                        <div class="field wide"><label for="camp-objective">Objective</label><input id="camp-objective" class="input" wire:model="form.objective" maxlength="200">@error('form.objective')<span class="field-error">{{ $message }}</span>@enderror</div>
                        <div class="field"><label for="camp-offer">Offer</label><input id="camp-offer" class="input" wire:model="form.offer" maxlength="300" placeholder="Launch −10% until 31 Oct"></div>
                        <div class="field"><label for="camp-audience">Audience</label><input id="camp-audience" class="input" wire:model="form.audience" maxlength="500"></div>
                        <div class="field"><label for="camp-start">Start date</label><input id="camp-start" class="input" type="date" wire:model="form.starts_on">@error('form.starts_on')<span class="field-error">{{ $message }}</span>@enderror</div>
                        <div class="field"><label for="camp-end">End date <span class="muted">(optional)</span></label><input id="camp-end" class="input" type="date" wire:model="form.ends_on">@error('form.ends_on')<span class="field-error">{{ $message }}</span>@enderror</div>
                        <div class="field wide"><label for="camp-hyp">Hypothesis</label><textarea id="camp-hyp" class="input" rows="2" wire:model="form.hypothesis" maxlength="1000" placeholder="Write the result you expect and why."></textarea></div>
                        <div class="field"><label for="camp-base">Baseline</label><input id="camp-base" class="input" wire:model="form.baseline" maxlength="200" placeholder="1.4% page conversion (September)"><span class="xs muted">Include a percentage to compare conversion against it.</span></div>
                        <div class="field"><label for="camp-source">Traffic source</label><input id="camp-source" class="input" wire:model="form.traffic_source" maxlength="200" placeholder="Newsletter (existing list)"></div>
                        <div class="field wide">
                            <label for="camp-page">Landing page</label>
                            <select id="camp-page" class="input" wire:model="form.page_id">
                                <option value="">No landing page</option>
                                @foreach($pageOptions as $option)
                                    <option value="{{ $option->id }}">{{ $option->name }} · {{ $option->live_version ? 'live v'.$option->live_version : 'not published' }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                    <fieldset class="camp-items">
                        <legend class="lbl">Deliverables</legend>
                        @forelse($items as $item)
                            <label class="opt" wire:key="opt-{{ $item->id }}">
                                <input type="checkbox" value="{{ $item->id }}" wire:model="form.items">
                                <span class="grow">{{ $item->title }} <span class="muted">v{{ $item->current_version }}</span></span>
                                @include('content.status-tag', ['status' => $item->status])
                            </label>
                        @empty
                            <span class="small muted">This product has no content yet. Create a launch pack first.</span>
                        @endforelse
                        <span class="xs muted">Launch uses each item's newest approved version.</span>
                    </fieldset>
                    <div class="row"><button type="submit" class="pill pri sm">Save plan</button><button type="button" class="pill ghost sm" wire:click="cancelEditing">Cancel</button><span class="sp"></span><button type="button" class="linkbtn" wire:click="deleteCampaign" wire:confirm="Delete this planned campaign?">Delete campaign</button></div>
                </form>
            @else
                <section class="card col c4">
                    <div class="row"><span class="eyebrow grow">{{ $campaign->name }}</span><span class="tag {{ $tone }}"><span class="dot"></span>{{ $label }}</span></div>
                    <div>
                        @php
                            $details = [
                            'Objective' => $campaign->objective, 'Offer' => $campaign->offer, 'Audience' => $campaign->audience,
                            'Dates' => $campaign->dateRange(), 'Hypothesis' => $campaign->hypothesis, 'Baseline' => $campaign->baseline,
                            'Traffic source' => $campaign->traffic_source,
                            'Launch record' => $campaign->launched_at ? $campaign->launched_at->format('j M Y, H:i').($campaign->launchedBy ? ' by '.$campaign->launchedBy->name : '') : 'Not launched',
                        ];
                        @endphp
                        @foreach($details as $key => $value)
                            <div class="kv"><span>{{ $key }}</span><span @class(['muted' => ! $value])>{{ $value ?: 'Not set' }}</span></div>
                        @endforeach
                    </div>
                    @if($campaign->follows)<span class="muted xs">Next experiment after <button type="button" class="linkbtn xs" wire:click="show({{ $campaign->follows->id }})">{{ $campaign->follows->name }}</button></span>@endif
                    <div class="row camp-actions">
                        @if($campaign->status === 'planned')
                            <button type="button" class="pill pri sm" wire:click="launch" @disabled(! $canLaunch) wire:loading.attr="disabled">Launch campaign</button>
                            <button type="button" class="pill ghost sm" wire:click="startEditing">Edit plan</button>
                        @elseif($campaign->status === 'active')
                            <button type="button" class="pill ghost sm" wire:click="pause">Pause</button>
                            <button type="button" class="pill ghost sm" wire:click="complete" wire:confirm="Mark this campaign completed?">Mark completed</button>
                        @elseif($campaign->status === 'paused')
                            <button type="button" class="pill ghost sm" wire:click="resume">Resume</button>
                            <button type="button" class="pill ghost sm" wire:click="complete" wire:confirm="Mark this campaign completed?">Mark completed</button>
                        @endif
                    </div>
                    <span class="muted xs">Launching records the context and pins versions. It does not start ad spend.</span>
                </section>
            @endif

            <section class="card col c4">
                <span class="eyebrow">Deliverables · exact versions</span>
                @forelse($campaign->deliverables as $deliverable)
                    @php $item = $deliverable->contentItem; @endphp
                    <div class="li" wire:key="deliv-{{ $deliverable->id }}">
                        <span class="camp-thumb">@include('content.thumb', ['item' => $item, 'height' => 40])</span>
                        <span class="grow small">{{ $item->title }}
                            @if($deliverable->pinned_version)
                                <strong class="medium">v{{ $deliverable->pinned_version }}</strong>
                                @if($item->current_version !== $deliverable->pinned_version)<br><span class="muted xs">v{{ $item->current_version }} exists; this campaign keeps v{{ $deliverable->pinned_version }}</span>@endif
                            @elseif($approved[$deliverable->id])
                                <strong class="medium">v{{ $approved[$deliverable->id] }}</strong>
                                @if($item->current_version !== $approved[$deliverable->id])<br><span class="muted xs">Newest approved; v{{ $item->current_version }} is {{ mb_strtolower(\App\Models\ContentItem::STATUSES[$item->status][1] ?? $item->status) }}</span>@endif
                            @else
                                <strong class="medium">v{{ $item->current_version }}</strong><br><span class="xs warn-text">No approved version yet. <a href="{{ route('review.index', ['item' => $item->id]) }}" wire:navigate>Review it</a></span>
                            @endif
                        </span>
                        @if($deliverable->pinned_version)
                            <span class="tag">Pinned</span>
                        @else
                            @include('content.status-tag', ['status' => $approved[$deliverable->id] ? 'approved' : $item->status])
                        @endif
                    </div>
                @empty
                    <span class="small muted">No deliverables yet. Edit the plan to choose approved content.</span>
                @endforelse
                @if($page)
                    <div class="li">
                        <span class="ic neu"><i class="ti ti-link" aria-hidden="true"></i></span>
                        <span class="grow small">{{ $page->name }} <strong class="medium">v{{ $campaign->pinned_page_version ?? $page->draft_version }}</strong>@if($page->remote_url)<br><span class="muted xs camp-url">{{ $page->remote_url }}</span>@endif</span>
                        @if($campaign->pinned_page_version)<span class="tag">Pinned</span>@elseif($page->live_version && $page->live_version === $page->draft_version)<span class="tag ok"><span class="dot"></span>Live · verified</span>@else<span class="tag"><span class="dot"></span>{{ $page->live_version ? 'Draft not published' : 'Not published' }}</span>@endif
                    </div>
                @endif
                @if($campaign->isPlanned())
                    <span class="eyebrow camp-ready">Readiness</span>
                    @foreach($readiness as $check)
                        <span class="small"><span class="{{ $check['ok'] ? 'ok-text' : 'muted' }}">{{ $check['ok'] ? '✓' : '○' }}</span> {{ $check['label'] }}</span>
                    @endforeach
                    @if($page && ! collect($readiness)->firstWhere('key', 'page')['ok'])<a class="small" href="{{ route('products.show', [$product, 'tab' => 'pages', 'page' => $page->id]) }}" wire:navigate>Publish the page first</a>@endif
                @endif
            </section>

            <section class="card col c4">
                <span class="eyebrow">Results</span>
                @include('campaigns.results-card', ['campaign' => $campaign, 'summary' => $summary, 'orderStore' => $orderStore])
            </section>

            @if($summary)
                <section class="card col c12">
                    <div class="row"><span class="eyebrow grow">Next experiment · proposals</span><span class="muted xs">Worked out from these results by fixed rules. They are proposals until you save one.</span></div>
                    @if($campaign->followUps->isNotEmpty())
                        <div class="banner ok"><i class="ti ti-check" aria-hidden="true"></i><span class="grow">Saved as planned: {{ $campaign->followUps->pluck('name')->implode(', ') }}. Each reuses {{ $product->name }} knowledge and this campaign's deliverables.</span><button type="button" class="pill ghost sm" wire:click="show({{ $campaign->followUps->last()->id }})">Open it</button></div>
                    @endif
                    <div class="grid camp-proposals">
                        @foreach($proposals as $index => $proposal)
                            @php $saved = $campaign->followUps->contains('name', $proposal['title']); @endphp
                            <div class="c4 col camp-proposal" wire:key="prop-{{ $index }}">
                                <strong class="medium">{{ $proposal['title'] }}</strong>
                                <span class="muted small">{{ $proposal['reason'] }}</span>
                                <span class="xs">Hypothesis: {{ $proposal['hypothesis'] }}</span>
                                <button type="button" class="pill ghost sm self-start" wire:click="saveProposal({{ $index }})" @disabled($saved)>{{ $saved ? 'Saved' : 'Save as next experiment' }}</button>
                            </div>
                        @endforeach
                    </div>
                </section>
                <section class="card col c12">
                    <span class="eyebrow">Imports</span>
                    @foreach($campaign->results as $result)
                        <div class="li small" wire:key="result-{{ $result->id }}">
                            <span class="ic neu"><i class="ti ti-{{ $result->source === 'store' ? 'shopping-cart' : 'file-spreadsheet' }}" aria-hidden="true"></i></span>
                            <span class="grow">{{ $result->source_label }}<br><span class="muted xs">{{ \App\Models\CampaignResult::SOURCES[$result->source] }} · {{ $result->window_start->format('j M') }}–{{ $result->window_end->format('j M Y') }} · imported {{ $result->created_at->diffForHumans() }}</span></span>
                            <button type="button" class="linkbtn" wire:click="deleteResult({{ $result->id }})" wire:confirm="Remove this import? Its numbers leave the results.">Remove</button>
                        </div>
                    @endforeach
                </section>
            @endif
        </div>
    @endif
</div>
