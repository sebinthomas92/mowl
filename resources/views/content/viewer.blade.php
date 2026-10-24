{{--
    The large review viewer for one output: the creative or copy on a dark stage, with the decision,
    details, comments, versions and edits beside it. Arrow keys step through outputs, Esc closes.
--}}
@php
    $pack = $selected->campaignPack;
    $imageUrl = $selected->banner_creative_id && $selected->campaign_pack_id ? route('campaign-banners.image', [$selected->campaign_pack_id, $selected->banner_creative_id]) : null;
    $isBanner = $selected->type === 'image' && $imageUrl;
    $typeLabel = \Illuminate\Support\Str::singular(\App\Models\ContentItem::TYPES[$selected->type] ?? $selected->type);
    $commentCount = $versions->sum(fn ($version) => $version->comments->count());
    $deciding = $canReview && in_array($latest->status, ['draft', 'review'], true);
    $blocking = collect($checks)->where('blocking', true)->where('ok', false);
    $tab = in_array($detailTab, ['preview', 'edit', 'versions', 'comments'], true) ? $detailTab : 'preview';
@endphp
<div class="viewer" role="dialog" aria-modal="true" aria-labelledby="viewer-title" wire:key="viewer"
     x-data x-trap.noscroll="true"
     @keydown.escape.window.prevent="$wire.close()"
     @keydown.window="if (['INPUT', 'TEXTAREA', 'SELECT'].includes($event.target.tagName) || $event.target.isContentEditable || $event.metaKey || $event.ctrlKey || $event.altKey) return; if ($event.key === 'ArrowLeft') { $event.preventDefault(); $wire.step(-1) } else if ($event.key === 'ArrowRight') { $event.preventDefault(); $wire.step(1) }">
    <div class="viewer-backdrop" wire:click="close" aria-hidden="true"></div>
    <div class="viewer-frame">
        <header class="viewer-bar">
            <div class="viewer-crumbs grow">
                <span class="muted xs viewer-crumb">{{ $selected->product->name }}@if($pack) · {{ $pack->name }}@endif</span>
                <h2 id="viewer-title" class="viewer-title">{{ $isBanner ? ($latest->body['headline'] ?? $selected->title) : $selected->title }}</h2>
            </div>
            @if($position !== null)<span class="xs muted num viewer-count" aria-live="polite">{{ $position + 1 }} of {{ $total }}</span>@endif
            <div class="row viewer-nav">
                <button type="button" class="viewer-btn" wire:click="step(-1)" @disabled(! $previousId) aria-label="Previous output" title="Previous (←)"><i class="ti ti-chevron-left" aria-hidden="true"></i></button>
                <button type="button" class="viewer-btn" wire:click="step(1)" @disabled(! $nextId) aria-label="Next output" title="Next (→)"><i class="ti ti-chevron-right" aria-hidden="true"></i></button>
                <button type="button" class="viewer-btn" wire:click="close" aria-label="Close" title="Close (Esc)"><i class="ti ti-x" aria-hidden="true"></i></button>
            </div>
        </header>

        <div class="viewer-body">
            <div @class(['viewer-stage', 'is-image' => $isBanner])>
                @if($isBanner)
                    <img class="viewer-img" src="{{ $imageUrl }}" alt="Banner: {{ $latest->body['headline'] ?? $selected->title }}" wire:key="viewer-img-{{ $selected->id }}">
                @else
                    <div class="viewer-paper col">
                        <span class="eyebrow">{{ $typeLabel }} · v{{ $latest->version }}</span>
                        @include('content.preview', ['item' => $selected, 'body' => $latest->body])
                    </div>
                @endif
                @if($previousId)<button type="button" class="viewer-arrow is-prev" wire:click="step(-1)" aria-label="Previous output" tabindex="-1"><i class="ti ti-chevron-left" aria-hidden="true"></i></button>@endif
                @if($nextId)<button type="button" class="viewer-arrow is-next" wire:click="step(1)" aria-label="Next output" tabindex="-1"><i class="ti ti-chevron-right" aria-hidden="true"></i></button>@endif
            </div>

            <aside class="viewer-panel" aria-label="Review {{ $selected->title }}">
                <div class="row viewer-status">
                    @include('content.status-tag', ['status' => $latest->status])
                    <span class="tag">v{{ $latest->version }}</span>
                    <span class="muted xs grow">{{ $typeLabel }}@if($latest->reviewed_by_name && in_array($latest->status, ['approved', 'changes'], true)) · {{ $latest->status === 'approved' ? 'approved' : 'changes asked' }} by {{ $latest->reviewed_by_name }}@if($latest->reviewed_at) on {{ $latest->reviewed_at->format('j M') }}@endif @endif</span>
                </div>

                @if($flash)<div class="banner ok xs" role="status"><i class="ti ti-circle-check" aria-hidden="true"></i><span>{{ $flash }}</span></div>@endif
                @error('decision')<div class="banner bad xs" role="alert"><i class="ti ti-alert-triangle" aria-hidden="true"></i><span>{{ $message }}</span></div>@enderror

                @if($deciding)
                    <section class="viewer-decide col" aria-labelledby="viewer-decide-title">
                        <span id="viewer-decide-title" class="eyebrow">Your decision on v{{ $latest->version }}</span>
                        @if($blocking->isNotEmpty())
                            <ul class="checks viewer-checks" aria-label="Blocking checks">
                                @foreach($blocking as $check)<li class="bad">✕ <span>{{ $check['label'] }}</span></li>@endforeach
                            </ul>
                        @endif
                        <div class="field">
                            <label for="viewer-note" class="sr">Note with your decision</label>
                            <textarea id="viewer-note" class="input" rows="2" wire:model="decisionNote" maxlength="2000" placeholder="Note: optional to approve, required to ask for changes"></textarea>
                            @error('decisionNote')<span class="err" role="alert">{{ $message }}</span>@enderror
                        </div>
                        <div class="row viewer-decide-actions">
                            <button type="button" class="pill pri" wire:click="approve" wire:loading.attr="disabled" @disabled($blocking->isNotEmpty())><i class="ti ti-check" aria-hidden="true"></i>Approve v{{ $latest->version }}</button>
                            <button type="button" class="pill ghost" wire:click="requestChanges" wire:loading.attr="disabled">Request changes</button>
                        </div>
                        <span class="muted xs">Approving marks this exact version as usable. It does not publish anything.</span>
                    </section>
                @elseif($canCreate && $latest->status === 'draft')
                    <section class="viewer-decide col">
                        <span class="eyebrow">Next step</span>
                        <p class="small">An owner or reviewer approves each output. Send this version to them.</p>
                        <button type="button" class="pill pri self-start" wire:click="submit">Submit v{{ $latest->version }} for review</button>
                    </section>
                @elseif($latest->status === 'review')
                    <div class="banner rev xs">Waiting for an owner or reviewer to decide on v{{ $latest->version }}.</div>
                @elseif($latest->status === 'changes')
                    <div class="banner warn xs">Changes were requested. @if($canCreate)Start a revision to edit it.@endif</div>
                @endif

                <div class="dtabs" role="tablist" aria-label="Output details">
                    @foreach(array_filter(['preview' => 'Details', 'comments' => 'Comments · '.$commentCount, 'versions' => 'Versions · '.$versions->count(), 'edit' => $canCreate ? 'Edit' : null]) as $key => $label)
                        <button type="button" role="tab" aria-selected="{{ $tab === $key ? 'true' : 'false' }}" wire:click="showTab('{{ $key }}')">{{ $label }}</button>
                    @endforeach
                </div>

                <div class="col viewer-tab" role="tabpanel">
                    @switch($tab)
                        @case('edit')
                            @if($latest->status !== 'draft')
                                <div @class(['banner', 'xs', 'ok' => $latest->status === 'approved', 'rev' => $latest->status === 'review', 'warn' => $latest->status === 'changes'])>
                                    {{ ['approved' => 'Approved versions are locked. Start a revision or create a variation to change it.', 'review' => 'This version is in review and locked.', 'changes' => 'Changes were requested. Start a revision to edit.'][$latest->status] ?? '' }}
                                </div>
                            @endif
                            @if($isBanner)<p class="xs muted">Edited banner text is checked and approved here; the image keeps the text it was made with. Make new banners in Banner Studio to change the picture.</p>@endif
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
                                <p class="small muted">This output has no free-text fields. Use Regenerate to change it.</p>
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
                        @default
                            @if($isBanner)
                                <div class="kv"><span>Headline</span><span>{{ $latest->body['headline'] ?? '' }}</span></div>
                                @if(filled($latest->body['supporting_text'] ?? null))<div class="kv"><span>Supporting text</span><span>{{ $latest->body['supporting_text'] }}</span></div>@endif
                                @if(filled($latest->body['cta'] ?? null))<div class="kv"><span>Call to action</span><span>{{ $latest->body['cta'] }}</span></div>@endif
                                <div class="kv"><span>Direction</span><span>{{ $latest->body['direction'] ?? '' }}</span></div>
                                <div class="kv"><span>Size</span><span>{{ $latest->body['size'] ?? '' }}</span></div>
                            @endif
                            @if($latest->note)<div class="kv"><span>This version</span><span>{{ $latest->note }}</span></div>@endif
                            <span class="eyebrow viewer-used">Used in</span>
                            @forelse($usedIn as $use)
                                <div class="li small"><i class="ti ti-{{ $use['icon'] }}" aria-hidden="true"></i><span class="grow">@if($use['url'])<a href="{{ $use['url'] }}" wire:navigate>{{ $use['text'] }}</a>@else{{ $use['text'] }}@endif</span></div>
                            @empty
                                <span class="muted small">Not used in a launch pack, page or campaign yet.</span>
                            @endforelse
                    @endswitch
                </div>

                <div class="row content-actions viewer-actions">
                    @if($downloadUrl)<a class="pill ghost sm" href="{{ $downloadUrl }}"><i class="ti ti-download" aria-hidden="true"></i>Download PNG</a>@endif
                    @if($canCreate && in_array($latest->status, ['changes', 'approved'], true))
                        <button type="button" @class(['pill sm', 'pri' => $latest->status === 'changes', 'ghost' => $latest->status !== 'changes']) wire:click="revise">Start revision v{{ $latest->version + 1 }}</button>
                    @endif
                    @if($canCreate && in_array($latest->status, ['approved', 'review'], true))
                        <button type="button" class="pill ghost sm" wire:click="variation">Create variation</button>
                    @endif
                    @if($canCreate && $regenerable)
                        <button type="button" class="pill ghost sm" wire:click="regenerate" wire:loading.attr="disabled" wire:target="regenerate" @disabled($packBusy)>
                            <i class="ti ti-refresh" aria-hidden="true"></i>{{ $packBusy ? 'Generating…' : 'Regenerate' }}
                        </button>
                    @endif
                    @if($isBanner && $pack)<a class="pill ghost sm" href="{{ route('campaign-packs.show', $pack) }}#banner-studio" wire:navigate>Banner Studio</a>@endif
                </div>
                <p class="xs muted viewer-keys" aria-hidden="true"><kbd>←</kbd> <kbd>→</kbd> move between outputs · <kbd>Esc</kbd> closes</p>
            </aside>
        </div>
    </div>
</div>
