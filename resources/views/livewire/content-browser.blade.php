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
    @if($flash)<div class="banner ok" role="status"><i class="ti ti-circle-check" aria-hidden="true"></i><span>{{ $flash }}</span></div>@endif
    @error('decision')<div class="banner bad" role="alert"><i class="ti ti-alert-triangle" aria-hidden="true"></i><span>{{ $message }}</span></div>@enderror
    @if($canCreate && $picked)
        <div class="banner neu"><span class="num">{{ count($picked) }} selected</span><button type="button" class="pill ghost sm" wire:click="bulkSubmit">Submit selected for review</button><button type="button" class="linkbtn" wire:click="$set('picked', [])">Clear</button></div>
    @endif

    <div class="content-layout">
        <div class="content-list">
            @if($all->isEmpty())
                <div class="empty">
                    <strong>No outputs yet</strong>
                    <span class="small muted">Every launch pack output (positioning, angles, video routes, offers, channel copy and banners) appears here on its own, with versions and its own approval.</span>
                    @if($canCreate)<a class="pill pri sm" href="{{ route('campaign-packs.create', array_filter(['product' => $productId])) }}" wire:navigate>Create a launch pack</a>@endif
                </div>
            @elseif($list->isEmpty())
                <div class="empty"><strong>Nothing matches these filters</strong><button type="button" class="pill ghost sm" wire:click="clearFilters">Clear filters</button></div>
            @else
                <div class="cgrid">
                    @foreach($list as $item)
                        <div class="ccard-wrap" wire:key="content-card-{{ $item->id }}">
                            <button type="button" class="ccard" aria-pressed="{{ $selected?->id === $item->id ? 'true' : 'false' }}" wire:click="select({{ $item->id }})">
                                @include('content.thumb', ['item' => $item, 'height' => 120])
                                <strong class="ccard-title">{{ $item->title }}</strong>
                                <span class="muted xs">@if($showProduct){{ $item->product->name }} · @endif{{ \Illuminate\Support\Str::singular(\App\Models\ContentItem::TYPES[$item->type] ?? $item->type) }} · v{{ $item->current_version }}</span>
                                @include('content.status-tag', ['status' => $item->status])
                            </button>
                            @if($canCreate)<input class="sel" type="checkbox" value="{{ $item->id }}" wire:model.live="picked" aria-label="Select {{ $item->title }}">@endif
                        </div>
                    @endforeach
                </div>
            @endif
        </div>

        @if($selected && $latest)
            @php($pack = $selected->campaignPack)
            <aside class="card col content-detail" aria-label="{{ $selected->title }}">
                <div class="row content-detail-head">
                    <div class="grow">
                        <strong class="medium content-detail-title">{{ $selected->title }}</strong> <span class="tag">v{{ $latest->version }}</span><br>
                        <span class="muted xs">{{ $selected->product->name }} · {{ \Illuminate\Support\Str::singular(\App\Models\ContentItem::TYPES[$selected->type] ?? $selected->type) }}@if($pack) · {{ $pack->name }}@endif</span>
                    </div>
                    @include('content.status-tag', ['status' => $latest->status])
                </div>

                <div class="dtabs" role="tablist" aria-label="Item details">
                    @foreach(array_filter(['preview' => 'Preview', 'edit' => $canCreate ? 'Edit' : null, 'versions' => 'Versions · '.$versions->count(), 'comments' => 'Comments · '.$versions->sum(fn ($version) => $version->comments->count()), 'used' => 'Used in']) as $key => $label)
                        <button type="button" role="tab" aria-selected="{{ $detailTab === $key ? 'true' : 'false' }}" wire:click="showTab('{{ $key }}')">{{ $label }}</button>
                    @endforeach
                </div>

                <div class="col">
                    @switch($detailTab)
                        @case('edit')
                            @if($latest->status !== 'draft')
                                <div @class(['banner', 'xs', 'ok' => $latest->status === 'approved', 'rev' => $latest->status === 'review', 'warn' => $latest->status === 'changes'])>
                                    {{ ['approved' => 'Approved versions are locked. Start a revision or create a variation to change it.', 'review' => 'This version is in review and locked.', 'changes' => 'Changes were requested. Start a revision to edit.'][$latest->status] ?? '' }}
                                </div>
                            @endif
                            @forelse($editable as $key => $value)
                                @php($label = collect(explode('__', $key))->reject(fn ($part) => is_numeric($part))->map(fn ($part) => str($part)->replace('_', ' ')->ucfirst())->implode(' · '))
                                @php($index = collect(explode('__', $key))->first(fn ($part) => is_numeric($part)))
                                <div @class(['field', 'is-focus' => $key === $focusField]) @if($key === $focusField) x-init="$nextTick(() => { $el.scrollIntoView({ block: 'center' }); $el.querySelector('.input')?.focus() })" @endif>
                                    <label for="cf-{{ $key }}">{{ $label }}@if($index !== null) <span class="muted">#{{ $index + 1 }}</span>@endif</label>
                                    @if(str_contains($value, "\n") || mb_strlen($value) > 90)
                                        <textarea id="cf-{{ $key }}" class="input" rows="3" wire:model="fields.{{ $key }}" @disabled($latest->status !== 'draft')></textarea>
                                    @else
                                        <input id="cf-{{ $key }}" class="input" wire:model="fields.{{ $key }}" @disabled($latest->status !== 'draft')>
                                    @endif
                                    @error('fields.'.$key)<span class="err">{{ $message }}</span>@enderror
                                </div>
                            @empty
                                <p class="small muted">This item has no free-text fields. Use Regenerate to change it.</p>
                            @endforelse
                            @if($selected->type === 'video')<p class="xs muted">Timed voiceover, captions and the shot plan change through Regenerate, so their timing stays checked.</p>@endif
                            @if($canCreate && $latest->status === 'draft' && $editable)
                                <button type="button" class="pill ghost sm self-start" wire:click="saveDraft">Save draft</button>
                            @endif
                            @break
                        @case('versions')
                            @foreach($versions as $version)
                                <div class="li">
                                    <span @class(['tag', 'tag-current' => $loop->first])>v{{ $version->version }}</span>
                                    <span class="grow small">{{ $version->note ?: 'Version '.$version->version }}<br><span class="xs muted">{{ $version->created_at->format('j M, H:i') }}@if($version->reviewed_by_name) · decided by {{ $version->reviewed_by_name }}@endif</span></span>
                                    @include('content.status-tag', ['status' => $version->status])
                                </div>
                            @endforeach
                            @break
                        @case('comments')
                            @php($comments = $versions->flatMap(fn ($version) => $version->comments->map(fn ($comment) => [$version, $comment]))->sortBy(fn ($pair) => $pair[1]->id))
                            @forelse($comments as [$version, $comment])
                                @include('content.comment', ['comment' => $comment, 'version' => $version])
                            @empty
                                <span class="muted small">No comments yet.</span>
                            @endforelse
                            <form class="col" wire:submit="addComment">
                                <div class="field"><label for="content-comment">Comment on v{{ $latest->version }}</label><textarea id="content-comment" class="input" rows="3" wire:model="commentBody" placeholder="Add a note for the team"></textarea>@error('commentBody')<span class="err">{{ $message }}</span>@enderror</div>
                                <button type="submit" class="pill ghost sm self-start">Add comment</button>
                            </form>
                            @break
                        @case('used')
                            @forelse($usedIn as $use)
                                <div class="li small"><i class="ti ti-{{ $use['icon'] }}" aria-hidden="true"></i><span class="grow">@if($use['url'])<a href="{{ $use['url'] }}" wire:navigate>{{ $use['text'] }}</a>@else{{ $use['text'] }}@endif</span></div>
                            @empty
                                <span class="muted small">Not used in a launch pack, page or campaign yet.</span>
                            @endforelse
                            @break
                        @default
                            @include('content.preview', ['item' => $selected, 'body' => $latest->body])
                    @endswitch
                </div>

                <div class="row content-actions">
                    @if(! $canCreate)
                        @if($latest->status === 'review')<a class="pill dark sm" href="{{ route('review.index', ['item' => $selected->id]) }}" wire:navigate>Open in review</a>@endif
                    @elseif($latest->status === 'draft')
                        <button type="button" class="pill pri sm" wire:click="submit">Submit v{{ $latest->version }} for review</button>
                        @if($detailTab !== 'edit' && $editable)<button type="button" class="pill ghost sm" wire:click="showTab('edit')">Edit</button>@endif
                    @elseif($latest->status === 'changes')
                        <button type="button" class="pill pri sm" wire:click="revise">Start revision v{{ $latest->version + 1 }}</button>
                    @elseif($latest->status === 'review')
                        <a class="pill dark sm" href="{{ route('review.index', ['item' => $selected->id]) }}" wire:navigate>Open in review</a>
                    @elseif($latest->status === 'approved')
                        <button type="button" class="pill ghost sm" wire:click="revise">Start revision v{{ $latest->version + 1 }}</button>
                    @endif
                    @if($canCreate && in_array($latest->status, ['approved', 'review'], true))
                        <button type="button" class="pill ghost sm" wire:click="variation">Create variation</button>
                    @endif
                    @if($canCreate && $regenerable)
                        <button type="button" class="pill ghost sm" wire:click="regenerate" wire:loading.attr="disabled" wire:target="regenerate" @disabled($packBusy)>
                            <i class="ti ti-refresh" aria-hidden="true"></i>{{ $packBusy ? 'Generating…' : 'Regenerate' }}
                        </button>
                    @endif
                </div>
            </aside>
        @endif
    </div>

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
