<div class="content-browser">
    @php($statuses = \App\Models\ContentItem::STATUSES)
    <div class="row">
        <div class="chips" role="group" aria-label="Type">
            <button type="button" class="chip" aria-pressed="{{ $type === 'all' ? 'true' : 'false' }}" wire:click="filter('type', 'all')">All · <span class="num">{{ $all->count() }}</span></button>
            @foreach(\App\Models\ContentItem::TYPES as $key => $label)
                <button type="button" class="chip" aria-pressed="{{ $type === $key ? 'true' : 'false' }}" wire:click="filter('type', '{{ $key }}')">{{ $label }} · <span class="num">{{ $all->where('type', $key)->count() }}</span></button>
            @endforeach
        </div>
        <span class="sp"></span>
        <label class="small muted" for="content-status">Status</label>
        <select id="content-status" class="input status-select" wire:change="filter('status', $event.target.value)">
            <option value="all" @selected($status === 'all')>Any status</option>
            @foreach($statuses as $key => [$tone, $label])<option value="{{ $key }}" @selected($status === $key)>{{ $label }}</option>@endforeach
        </select>
    </div>

    @if($showProduct && trim($search) !== '')
        <div class="row small"><span class="muted">Showing results for “{{ $search }}”</span><button type="button" class="linkbtn" wire:click="$set('search', '')">Clear search</button></div>
    @endif
    @if($flash && ! $selected)<div class="banner ok" role="status"><i class="ti ti-circle-check" aria-hidden="true"></i><span>{{ $flash }}</span></div>@endif
    @if(! $selected)@error('decision')<div class="banner bad" role="alert"><i class="ti ti-alert-triangle" aria-hidden="true"></i><span>{{ $message }}</span></div>@enderror @endif
    @if($canCreate && $picked)
        <div class="banner neu"><span class="num">{{ count($picked) }} selected</span><button type="button" class="pill ghost sm" wire:click="bulkSubmit">Submit selected for review</button><button type="button" class="linkbtn" wire:click="$set('picked', [])">Clear</button></div>
    @endif

    @if($all->isEmpty())
        <div class="empty">
            <strong>No outputs yet</strong>
            <span class="small muted">Every launch pack output (positioning, angles, video routes, offers, channel copy and banners) appears here on its own, with versions and its own approval.</span>
            @if($canCreate)<a class="pill pri sm" href="{{ route('campaign-packs.create', array_filter(['product' => $productId])) }}" wire:navigate>Create a launch pack</a>@endif
        </div>
    @elseif($list->isEmpty())
        <div class="empty"><strong>Nothing matches these filters</strong><button type="button" class="pill ghost sm" wire:click="clearFilters">Clear filters</button></div>
    @else
        <div class="out-groups">
            @foreach($groups as $group)
                @php($open = $loop->first || $filtersOn || $groups->count() === 1)
                <details class="out-group" wire:key="{{ $group['key'] }}" wire:ignore.self @if($open) open @endif>
                    <summary class="out-group-head">
                        <i class="ti ti-chevron-down out-chevron" aria-hidden="true"></i>
                        <span class="grow">
                            <strong class="medium out-group-title">{{ $group['title'] }}</strong>
                            @if($group['meta'])<span class="muted xs">{{ $group['meta'] }}</span>@endif
                        </span>
                        <span class="out-counts xs">
                            @foreach(['approved', 'review', 'changes', 'draft'] as $state)
                                @if($group['counts'][$state] ?? 0)<span class="out-count is-{{ $state }}"><span class="dot"></span>{{ $group['counts'][$state] }} {{ mb_strtolower(\App\Models\ContentItem::STATUSES[$state][1]) }}</span>@endif
                            @endforeach
                        </span>
                    </summary>
                    @foreach($group['sets'] as $set)
                        <section class="out-set" aria-label="{{ $group['title'] }}: {{ $set['title'] }}">
                            <div class="out-set-head"><span class="eyebrow">{{ $set['title'] }}</span>@if($set['meta'])<span class="muted xs">{{ $set['meta'] }}</span>@endif</div>
                            <div @class(['cgrid', 'cgrid-banners' => $set['banners']])>
                                @foreach($set['items'] as $item)
                                    <div class="ccard-wrap" wire:key="content-card-{{ $item->id }}">
                                        <button type="button" class="ccard" aria-pressed="{{ $selected?->id === $item->id ? 'true' : 'false' }}" aria-haspopup="dialog" wire:click="select({{ $item->id }})">
                                            @include('content.thumb', ['item' => $item, 'height' => 120, 'ratio' => $item->banner_creative_id ? '4 / 5' : null, 'snippet' => true])
                                            <strong class="ccard-title">{{ $item->banner_creative_id ? ($item->latestVersion?->body['headline'] ?? $item->title) : $item->title }}</strong>
                                            <span class="muted xs ccard-meta">@if($showProduct){{ $item->product->name }} · @endif{{ $item->banner_creative_id ? \Illuminate\Support\Str::after($item->title, 'Banner · ') : \Illuminate\Support\Str::singular(\App\Models\ContentItem::TYPES[$item->type] ?? $item->type) }} · v{{ $item->current_version }}</span>
                                            @include('content.status-tag', ['status' => $item->status])
                                        </button>
                                        @if($canCreate)<input class="sel" type="checkbox" value="{{ $item->id }}" wire:model.live="picked" aria-label="Select {{ $item->title }}">@endif
                                    </div>
                                @endforeach
                            </div>
                        </section>
                    @endforeach
                </details>
            @endforeach
        </div>
    @endif

    @if($selected && $latest)
        @include('content.viewer')
    @endif

    @if($productId && $isOwner && $clientLinks->isNotEmpty())
        <section class="card col client-links" aria-labelledby="client-links-title">
            <div class="row"><span id="client-links-title" class="eyebrow grow">Older review links</span><a class="small" href="{{ route('products.show', [$productId, 'tab' => 'hub']) }}" wire:navigate>Share from the Client hub tab instead</a></div>
            <p class="muted xs">These links still work until they expire. Each opens the same client page as the client link.</p>
            @foreach($clientLinks as $clientLink)
                <div class="li small" wire:key="client-link-{{ $clientLink->id }}">
                    <span class="grow"><strong class="medium">{{ $clientLink->client_name }}</strong>@if($clientLink->client_email) · {{ $clientLink->client_email }}@endif<br><span class="xs muted">Expires {{ $clientLink->expires_at->format('j M') }}</span></span>
                    <button type="button" class="linkbtn" wire:click="revokeClientLink({{ $clientLink->id }})" wire:confirm="Turn off this link? The client will no longer be able to open it.">Turn off</button>
                </div>
            @endforeach
        </section>
    @endif
</div>
