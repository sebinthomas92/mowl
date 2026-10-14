<div class="app-shell">
    <a class="skip-link" href="#main-content">Skip to review queue</a>
    @include('partials.workspace-sidebar')
    <div class="app-main">
        @include('partials.top-bar')
        <main id="main-content" class="review-page lib-page">
            <div class="pagehead">
                <div>
                    <div class="eyebrow">Review</div>
                    @if($selected && $current)
                        <h1 class="display">{{ $selected->title }} <span class="tag">v{{ $current->version }} · in review</span></h1>
                        <p class="muted">{{ $selected->product->name }}@if($current->submitted_at) · submitted {{ $current->submitted_at->diffForHumans() }}@endif @if($previous) · revision of v{{ $previous->version }}{{ $previous->status === 'changes' ? ' after changes were requested' : '' }}@endif</p>
                    @else
                        <h1 class="display">Review queue</h1>
                    @endif
                </div>
            </div>

            @if($flash)<div class="banner ok" role="status"><i class="ti ti-circle-check" aria-hidden="true"></i><span>{{ $flash }}</span></div>@endif

            <div class="review-layout">
                <aside class="card review-queue" aria-label="Awaiting decision">
                    <div class="eyebrow">Awaiting decision · {{ $queue->count() }}</div>
                    @forelse($queue as $entry)
                        <button type="button" wire:key="queue-{{ $entry->id }}" @class(['note-item', 'unread' => $selected?->id === $entry->id]) wire:click="select({{ $entry->id }})" @if($selected?->id === $entry->id) aria-current="true" @endif>
                            @include('content.thumb', ['item' => $entry, 'height' => 40])
                            <span class="grow"><strong class="medium">{{ $entry->title }}</strong> v{{ $entry->current_version }}<br><span class="muted xs">{{ $entry->product->name }}</span></span>
                        </button>
                    @empty
                        <p class="muted small">Nothing waiting.</p>
                    @endforelse
                    @if($recent->isNotEmpty())
                        <div class="eyebrow muted">Recently decided</div>
                        @foreach($recent as $entry)
                            <div class="row small"><span class="grow">{{ $entry->title }} v{{ $entry->current_version }}</span>@include('content.status-tag', ['status' => $entry->status])</div>
                        @endforeach
                    @endif
                </aside>

                @if(! $selected || ! $current)
                    <div class="empty review-main">
                        <strong>The queue is clear</strong>
                        <span class="muted small">Submitted versions appear here with the exact version number you are deciding on.</span>
                        <a class="pill ghost sm" href="{{ route('library.index') }}" wire:navigate>Go to Library</a>
                    </div>
                @else
                    <section class="col review-main">
                        <div @class(['review-compare', 'single' => ! $previous])>
                            @if($previous)
                                <div class="card col review-version is-previous">
                                    <div class="row small"><strong class="medium">v{{ $previous->version }}</strong><span class="sp"></span>@include('content.status-tag', ['status' => $previous->status])</div>
                                    <span class="muted xs">{{ $previous->note }}</span>
                                    @include('content.preview', ['item' => $selected, 'body' => $previous->body])
                                </div>
                            @endif
                            <div class="card col review-version is-current">
                                <div class="row small"><strong class="medium">v{{ $current->version }}</strong><span class="sp"></span>@include('content.status-tag', ['status' => 'review'])</div>
                                <span class="muted xs">{{ $current->note }}</span>
                                @include('content.preview', ['item' => $selected, 'body' => $current->body])
                            </div>
                        </div>
                        <section class="card col">
                            <span class="eyebrow">Comments</span>
                            @forelse($versions->sortBy('version')->flatMap(fn ($version) => $version->comments->map(fn ($comment) => [$version, $comment])) as [$version, $comment])
                                @include('content.comment', ['comment' => $comment, 'version' => $version])
                            @empty
                                <span class="muted small">No comments yet.</span>
                            @endforelse
                            <div class="field">
                                <label for="rv-comment">Comment on v{{ $current->version }}</label>
                                <textarea id="rv-comment" class="input" rows="3" wire:model="comment" placeholder="Required when you request changes" aria-describedby="rv-err" @error('comment') aria-invalid="true" @enderror></textarea>
                                @error('comment')<span id="rv-err" class="err" role="alert">{{ $message }}</span>@enderror
                            </div>
                        </section>
                    </section>

                    <aside class="card col review-decision" aria-label="Decision">
                        <span class="eyebrow">Your decision on v{{ $current->version }}</span>
                        <span class="muted xs">Reviewing as {{ auth()->user()->name }} · {{ $isOwner ? 'Owner' : 'Team member' }}</span>
                        <p class="small">Approval marks this exact version as usable. It does not publish anything.</p>
                        @error('decision')<div class="banner bad xs" role="alert">{{ $message }}</div>@enderror
                        @if($isOwner)
                            <button type="button" class="pill dark block" wire:click="approve" wire:loading.attr="disabled">Approve v{{ $current->version }}</button>
                            <button type="button" class="pill ghost block" wire:click="requestChanges" wire:loading.attr="disabled">Request changes</button>
                            @if($sourceNeedsApproval)
                                <div class="banner warn xs">The product source is not approved yet, so launch pack sections cannot be approved.<button type="button" class="linkbtn" wire:click="approveSource">Approve source</button></div>
                            @endif
                        @else
                            <div class="banner neu xs">Only workspace owners can approve or request changes. You can still comment.</div>
                        @endif
                        <ul class="checks" aria-label="Automatic checks">
                            @foreach($checks as $check)
                                <li class="{{ $check['ok'] ? 'ok' : ($check['blocking'] ? 'bad' : 'warn') }}">{{ $check['ok'] ? '✓' : ($check['blocking'] ? '✕' : '!') }} <span>{{ $check['label'] }}</span></li>
                            @endforeach
                        </ul>
                        <a class="small" href="{{ route('products.show', [$selected->product_id, 'tab' => 'content', 'item' => $selected->id]) }}" wire:navigate>Open in Content</a>
                    </aside>
                @endif
            </div>
        </main>
    </div>
</div>
