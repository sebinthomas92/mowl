<div class="pages-tab" @if($page && $page->publish_state === 'verifying') wire:poll.5s="verifyPending" @endif>
    @if(! $page)
        <div class="empty">
            <strong>No pages yet</strong>
            <span class="small muted">A landing page is built from approved content. Start a draft now and bind content as it gets approved. Publishing sends it to your WooCommerce store.</span>
            <button type="button" class="pill pri sm" wire:click="startPage" wire:loading.attr="disabled">Start a page draft</button>
        </div>
    @else
        @php
            $stateKey = $page->publish_state === 'live' && $page->live_version !== $page->draft_version ? 'idle' : $page->publish_state;
            [$tone, $stateLabel] = \App\Models\Page::STATES[$stateKey];
            if ($stateKey === 'idle' && $page->live_version) { $stateLabel = "Draft v{$page->draft_version} not published"; }
            $busy = $page->isBusy();
            $isLive = $page->publish_state === 'live' && $page->live_version === $page->draft_version;
        @endphp

        <div class="row pages-switch">
                <div class="chips" role="group" aria-label="Pages">
                    @foreach($pages as $option)
                        <button type="button" class="chip" aria-pressed="{{ $option->id === $page->id ? 'true' : 'false' }}" wire:click="showPage({{ $option->id }})">{{ $option->name }}</button>
                    @endforeach
                </div>
                <button type="button" class="linkbtn" wire:click="startPage"><i class="ti ti-plus" aria-hidden="true"></i> New page</button>
            </div>

        @if($flash)<div class="banner ok" role="status"><i class="ti ti-circle-check" aria-hidden="true"></i><span>{{ $flash }}</span></div>@endif
        @error('page')<div class="banner bad" role="alert"><i class="ti ti-alert-triangle" aria-hidden="true"></i><span>{{ $message }}</span></div>@enderror

        <div class="pages-layout">
            <aside class="card col pages-sections" aria-label="Sections and content">
                <span class="eyebrow">Sections and content</span>
                @foreach($sections as $section)
                    @php $item = $section->contentItem; @endphp
                    <div class="pages-section" wire:key="section-{{ $section->id }}">
                        <div class="row small">
                            <strong class="medium grow">{{ $section->title }}</strong>
                            <span class="pages-section-tools">
                                <button type="button" class="iconbtn sm" wire:click="move({{ $section->id }}, -1)" aria-label="Move {{ $section->title }} up" @disabled($loop->first)><i class="ti ti-arrow-up" aria-hidden="true"></i></button>
                                <button type="button" class="iconbtn sm" wire:click="move({{ $section->id }}, 1)" aria-label="Move {{ $section->title }} down" @disabled($loop->last)><i class="ti ti-arrow-down" aria-hidden="true"></i></button>
                                <button type="button" class="iconbtn sm" wire:click="removeSection({{ $section->id }})" wire:confirm="Remove the {{ $section->title }} section from this draft?" aria-label="Remove {{ $section->title }}"><i class="ti ti-trash" aria-hidden="true"></i></button>
                            </span>
                        </div>
                        @if($item)
                            <div class="row">@include('content.status-tag', ['status' => $item->status])</div>
                        @elseif($section->kind === 'specs')
                            <div class="row"><span class="tag">From Knowledge</span></div>
                        @elseif($section->kind !== 'cta')
                            <div class="row"><span class="tag">Empty</span></div>
                        @endif
                        @if($section->accepts())
                            @php $options = $items->where('type', $section->accepts())->filter(fn ($option) => $option->status === 'approved' || $option->id === $item?->id); @endphp
                            <select class="input input-sm" aria-label="Content for {{ $section->title }}" wire:change="bind({{ $section->id }}, $event.target.value)">
                                <option value="">{{ $item ? 'Unbind' : ($options->isEmpty() ? 'No approved '.($section->accepts() === 'image' ? 'images' : 'copy').' yet' : 'Choose approved content') }}</option>
                                @foreach($options as $option)
                                    <option value="{{ $option->id }}" @selected($option->id === $item?->id)>{{ $option->title }} v{{ $option->current_version }}{{ $option->status !== 'approved' ? ' (not approved)' : '' }}</option>
                                @endforeach
                            </select>
                            @if($item && $item->status !== 'approved')
                                <span class="xs warn-text">Blocks publishing until approved or unbound. <a href="{{ route('review.index', ['item' => $item->id]) }}" wire:navigate>Review it</a></span>
                            @elseif($section->kind === 'hero' && ! $item)
                                <span class="xs muted">Without a banner, the hero uses the product image.</span>
                            @endif
                        @elseif($section->kind === 'specs')
                            <span class="xs muted">Confirmed price, availability, benefits and differentiators. <a href="{{ route('products.show', [$product, 'tab' => 'knowledge']) }}" wire:navigate>Edit Knowledge</a></span>
                        @elseif($section->kind === 'cta')
                            <form class="col pages-cta" wire:submit="saveCta({{ $section->id }})">
                                <div class="field"><label for="cta-label-{{ $section->id }}">Button label</label><input id="cta-label-{{ $section->id }}" class="input input-sm" wire:model="cta.{{ $section->id }}.label" maxlength="40"></div>
                                @error("cta.{$section->id}.label")<span class="field-error">{{ $message }}</span>@enderror
                                <div class="field"><label for="cta-url-{{ $section->id }}">Button link</label><input id="cta-url-{{ $section->id }}" class="input input-sm" type="url" wire:model="cta.{{ $section->id }}.url" placeholder="https://"></div>
                                @error("cta.{$section->id}.url")<span class="field-error">{{ $message }}</span>@enderror
                                <div class="field"><label for="cta-text-{{ $section->id }}">Text above the button <span class="muted">(optional)</span></label><input id="cta-text-{{ $section->id }}" class="input input-sm" wire:model="cta.{{ $section->id }}.text" maxlength="300"></div>
                                <button type="submit" class="pill ghost sm self-start">Save</button>
                            </form>
                        @endif
                    </div>
                @endforeach

                <details class="pages-add">
                    <summary class="linkbtn"><i class="ti ti-plus" aria-hidden="true"></i> Add a section</summary>
                    <div class="chips">
                        @foreach($kinds as $kind => [$label])
                            <button type="button" class="chip" wire:click="addSection('{{ $kind }}')">{{ $label }}</button>
                        @endforeach
                    </div>
                </details>

                <form class="col pages-details" wire:submit="saveDetails">
                    <div class="field"><label for="pg-head">Hero headline</label><input id="pg-head" class="input" wire:model="headline" maxlength="200"></div>
                    @error('headline')<span class="field-error">{{ $message }}</span>@enderror
                    <div class="field"><label for="pg-name">Page title</label><input id="pg-name" class="input" wire:model="name" maxlength="120"></div>
                    @error('name')<span class="field-error">{{ $message }}</span>@enderror
                    <div class="field"><label for="pg-slug">Address</label><input id="pg-slug" class="input" wire:model="slug" maxlength="120" spellcheck="false"></div>
                    @error('slug')<span class="field-error">{{ $message }}</span>@enderror
                    <button type="submit" class="pill ghost sm self-start">Save page</button>
                </form>
                <span class="muted xs">{{ $frozen ? "v{$page->draft_version} was published. Your next edit starts draft v".($page->draft_version + 1).'.' : 'Editing a published page starts a new draft version.' }}</span>
            </aside>

            <section class="col pages-preview" x-data="{ device: 'desktop' }" aria-label="Preview">
                <div class="row">
                    <div class="chips" role="group" aria-label="Preview size">
                        @foreach(['desktop' => 'Desktop', 'tablet' => 'Tablet', 'mobile' => 'Mobile'] as $key => $label)
                            <button type="button" class="chip" :aria-pressed="device === '{{ $key }}'" @click="device = '{{ $key }}'">{{ $label }}</button>
                        @endforeach
                    </div>
                    <span class="sp"></span>
                    <span class="tag">Draft v{{ $page->draft_version }}</span>
                    @if($page->live_version)<span class="tag ok"><span class="dot"></span>Live v{{ $page->live_version }}</span>@endif
                </div>
                <div class="pages-device" :class="'is-' + device">
                    <div class="pv">
                        @foreach($sections as $section)
                            @php $item = $section->contentItem; @endphp
                            <div @class(['pvsec', 'block' => $item && $item->status !== 'approved']) wire:key="pv-{{ $section->id }}">
                                <div class="pvlabel">
                                    @if($item)
                                        @php [$itemTone] = \App\Models\ContentItem::STATUSES[$item->status] ?? ['']; @endphp
                                        <span class="tag {{ $itemTone }}"><span class="dot"></span>{{ $item->title }} v{{ $item->current_version }}</span>
                                    @else
                                        <span class="tag">{{ $section->kind === 'specs' ? 'From Knowledge' : ($section->kind === 'cta' ? 'Button' : 'Empty') }}</span>
                                    @endif
                                </div>
                                <div class="eyebrow pv-eyebrow">{{ $section->title }}</div>
                                @if(filled($preview[$section->id] ?? ''))
                                    <div class="pv-body">{!! $preview[$section->id] !!}</div>
                                @else
                                    <div class="muted small">{{ $section->kind === 'specs' ? 'No confirmed facts to show yet.' : ($section->kind === 'cta' ? 'Add a button link to show this section.' : 'Empty section. Bind approved content or remove it.') }}</div>
                                @endif
                            </div>
                        @endforeach
                    </div>
                </div>
            </section>

            <aside class="card col pages-publish" aria-label="Publish">
                <div class="row"><span class="eyebrow">Publish</span><span class="sp"></span><span class="tag {{ $tone }}"><span class="dot"></span>{{ $stateLabel }}</span></div>
                <div>
                    <div class="kv">
                        <span>Destination</span>
                        @if($stores->isEmpty())
                            <span><a href="{{ route('workspace.settings') }}#connections" wire:navigate>Connect a store</a></span>
                        @else
                            <select class="input input-sm pages-store" aria-label="Destination store" wire:change="chooseStore($event.target.value)" @disabled($busy || ! $isOwner)>
                                <option value="">Choose a store</option>
                                @foreach($stores as $option)
                                    <option value="{{ $option->id }}" @selected($option->id === $page->store_connection_id)>{{ $option->name }} ({{ $option->label() }})</option>
                                @endforeach
                            </select>
                        @endif
                    </div>
                    @if($store)
                        <div class="kv"><span>Connection</span><span>@if($store->isConnected())<span class="tag ok"><span class="dot"></span>Connected</span>@else<span class="tag warn"><span class="dot"></span>Needs attention</span>@endif</span></div>
                    @endif
                    <div class="kv"><span>Address</span><span class="break">@if($page->remote_url)<a href="{{ $page->remote_url }}" target="_blank" rel="noopener">{{ $page->remote_url }}</a>@else{{ '/'.$page->slug }}@endif</span></div>
                    <div class="kv"><span>Version</span><span>v{{ $page->draft_version }}@if($page->live_version && $page->live_version !== $page->draft_version) replaces live v{{ $page->live_version }}@endif</span></div>
                </div>

                @if($blockers)
                    <div class="banner warn xs"><i class="ti ti-alert-triangle" aria-hidden="true"></i><span><strong>{{ trans_choice(':count blocker|:count blockers', count($blockers)) }}.</strong> {{ implode(' ', $blockers) }}</span></div>
                @endif
                @if($store && ! $store->isConnected())
                    <div class="banner warn xs"><i class="ti ti-plug-x" aria-hidden="true"></i><span>{{ $store->last_error ?: 'The store connection needs attention.' }} <a href="{{ route('workspace.settings') }}#connections" wire:navigate>Reconnect the store</a></span></div>
                @endif
                @if($page->publish_state === 'failed' && $page->last_error)
                    <div class="banner bad xs" role="alert"><i class="ti ti-alert-triangle" aria-hidden="true"></i><span>{{ $page->last_error }}</span></div>
                @endif
                @error('publish')<div class="banner bad xs" role="alert"><i class="ti ti-alert-triangle" aria-hidden="true"></i><span>{{ $message }}</span></div>@enderror

                @if($page->publish_state !== 'idle' || $page->live_version)
                    @php
                        $at = ['publishing' => 0, 'failed' => 0, 'verifying' => 1, 'live' => 2][$page->publish_state] ?? -1;
                        $failedStep = $page->publish_state === 'failed' ? ($attempts->first()?->status === 'unverified' ? 1 : 0) : null;
                        $shown = $attempts->first()?->pageVersion?->version ?? $page->draft_version;
                    @endphp
                    <ol class="timeline" aria-label="Publishing steps">
                        @foreach([['Publishing', "Send v{$shown} to the store"], ['Verification pending', 'Fetch the page address and compare'], ['Verified live', 'Confirm the store serves this version']] as $index => [$label, $detail])
                            @php $dot = $failedStep === $index ? 'bad' : (($failedStep !== null ? $index < $failedStep : ($index < $at || $page->publish_state === 'live')) ? 'ok' : ($index === $at && $failedStep === null ? 'cur' : '')); @endphp
                            <li class="tstep"><span class="tdot {{ $dot }}" aria-hidden="true"></span><span><strong class="medium">{{ $failedStep === $index ? ($index === 0 ? 'Publish failed' : 'Not verified') : $label }}</strong><br><span class="muted xs">{{ $index === 2 && $page->publish_state === 'live' && $page->verified_at ? 'Checked '.$page->verified_at->diffForHumans() : $detail }}</span></span></li>
                        @endforeach
                    </ol>
                @endif

                @if($isOwner)
                    <button type="button" class="pill pri block" wire:click="publish" wire:loading.attr="disabled" wire:target="publish,rollback" @disabled($blockers || $busy || $isLive || ! $store || ! $store->isConnected())>
                        <span wire:loading.remove wire:target="publish">{{ $page->publish_state === 'failed' ? "Retry publishing v{$page->draft_version}" : ($isLive ? "v{$page->draft_version} is live" : "Publish v{$page->draft_version}") }}</span>
                        <span wire:loading wire:target="publish">Publishing…</span>
                    </button>
                    @if($rollbackTo && ! $busy)
                        <button type="button" class="pill ghost block" wire:click="rollback({{ $rollbackTo }})" wire:confirm="Put v{{ $rollbackTo }} back on the live page? Your draft is kept." wire:loading.attr="disabled" wire:target="publish,rollback">Roll back live to v{{ $rollbackTo }}</button>
                    @endif
                @else
                    <span class="banner xs"><i class="ti ti-lock" aria-hidden="true"></i><span>Only workspace owners can publish pages.</span></span>
                @endif

                @if($attempts->isNotEmpty())
                    <div class="col pages-log">
                        @foreach($attempts as $attempt)
                            <span class="muted xs">{{ $attempt->created_at->format('j M, H:i') }} · {{ $attempt->action === 'rollback' ? 'Rollback to' : 'Publish' }} v{{ $attempt->pageVersion?->version }} · {{ ['publishing' => 'sending', 'verifying' => 'verifying', 'live' => 'verified live', 'failed' => 'failed', 'unverified' => 'not verified'][$attempt->status] ?? $attempt->status }}@if($attempt->user) by {{ $attempt->user->name }}@endif</span>
                        @endforeach
                    </div>
                @endif
                <span class="muted xs">A save is not a publish, and a publish is not live until the page address serves the version.</span>
            </aside>
        </div>
    @endif
</div>
