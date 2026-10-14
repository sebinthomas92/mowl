<div class="client-page">
    <a class="skip-link" href="#main-content">Skip to review</a>
    <header class="client-head">
        <svg width="30" height="20" viewBox="0 0 30 20" aria-hidden="true"><circle cx="9" cy="10" r="8" fill="#FF5A3D"/><circle cx="21" cy="10" r="8" fill="#EA38D0" opacity=".85"/><circle cx="9" cy="10" r="2.5" fill="#fff"/><circle cx="21" cy="10" r="2.5" fill="#fff"/></svg>
        <div class="grow">
            <strong class="medium">{{ $brand?->name ?? $workspace->name }} · review</strong><br>
            <span class="muted xs">Shared by {{ $workspace->name }}</span>
        </div>
        @if($preview)
            <span class="tag rev">Previewing as a client</span>
            <a class="pill ghost sm" href="{{ route('products.index') }}" wire:navigate><i class="ti ti-arrow-left" aria-hidden="true"></i> Back to workspace</a>
        @else
            <span class="tag">Signed in as {{ auth()->user()->name }} · Client</span>
            @if($workspaces->count() > 1)
                <details class="account">
                    <summary class="pill ghost sm">Switch workspace</summary>
                    <div class="menu menu-end" role="menu">
                        @foreach($workspaces as $option)
                            <form method="POST" action="{{ route('workspaces.select', $option) }}">@csrf<button type="submit" role="menuitem" @if($option->id === $workspace->id) aria-current="true" @endif>{{ $option->name }}</button></form>
                        @endforeach
                    </div>
                </details>
            @endif
            <form method="POST" action="{{ route('logout') }}">@csrf<button type="submit" class="pill ghost sm">Sign out</button></form>
        @endif
    </header>

    <main class="client-main" id="main-content">
        @if($preview)
            <div class="banner rev small" role="note">
                <i class="ti ti-eye" aria-hidden="true"></i>
                <span class="grow">This is what a client of {{ $brand?->name ?? 'a brand' }} sees. Decisions are made by the client; yours happen in the review queue.</span>
                @if($brands->count() > 1)
                    <span class="chips" role="group" aria-label="Brand to preview">
                        @foreach($brands as $option)
                            <button type="button" class="chip" aria-pressed="{{ $brand?->id === $option->id ? 'true' : 'false' }}" wire:click="$set('previewBrand', {{ $option->id }})">{{ $option->name }}</button>
                        @endforeach
                    </span>
                @endif
            </div>
        @endif

        <div>
            <div class="eyebrow">Client view</div>
            <h1 class="display">Hello, {{ $preview ? 'client' : \Illuminate\Support\Str::before(auth()->user()->name, ' ') }}.</h1>
            @if($brand)
                <p class="muted">{{ $pending->isEmpty() ? 'Nothing needs your decision right now.' : trans_choice(':count item needs your decision.|:count items need your decision.', $pending->count()) }} {{ trans_choice(':count approved deliverable is ready.|:count approved deliverables are ready.', $approved->count()) }}</p>
            @endif
        </div>

        @if(! $brand)
            <div class="empty">
                <strong>{{ $preview ? 'Add a brand to preview the client view' : 'Your access is not set up yet' }}</strong>
                <span class="muted small">{{ $preview ? 'Clients see the work of one brand.' : 'Ask '.$workspace->name.' to share a brand with you.' }}</span>
            </div>
        @else
            @if($flash)<div class="banner ok" role="status"><i class="ti ti-circle-check" aria-hidden="true"></i><span>{{ $flash }}</span></div>@endif
            @unless($available)
                <div class="banner neu small" role="status"><span>Review items appear here after the next update.</span></div>
            @endunless

            @foreach($pending as $item)
                @php($version = $item->latestVersion)
                @php($previous = $item->versions->firstWhere('version', $version->version - 1))
                @php($imageUrl = $item->banner_creative_id ? route('portal.image', $item) : null)
                <section class="card col" wire:key="portal-item-{{ $item->id }}" aria-labelledby="portal-item-{{ $item->id }}">
                    <div class="row"><span class="eyebrow grow">Awaiting your review</span><span class="tag rev"><span class="dot"></span>{{ $item->title }} · version {{ $version->version }}</span></div>
                    <div class="client-item">
                        <div class="client-preview col">@include('content.preview', ['item' => $item, 'body' => $version->body, 'imageUrl' => $imageUrl])</div>
                        <div class="col">
                            <div><h2 id="portal-item-{{ $item->id }}" class="medium portal-title">{{ $item->title }}</h2><span class="muted xs">{{ $item->product->name }}</span></div>
                            @if($previous)
                                <div class="small"><span class="muted">What changed since version {{ $previous->version }}</span><br>{{ $version->note ?: 'A revised version.' }}</div>
                            @endif
                            @foreach($item->versions->sortBy('version') as $earlier)
                                @foreach($earlier->comments as $comment)
                                    <div class="msg portal-note">
                                        <span class="av" aria-hidden="true">{{ mb_strtoupper(mb_substr($comment->author_name, 0, 1)) }}</span>
                                        <span><strong class="medium">{{ $comment->user_id === auth()->id() ? 'You' : $comment->author_name }}</strong> <span class="muted xs">on version {{ $earlier->version }}</span><br>{{ $comment->body }}</span>
                                    </div>
                                @endforeach
                            @endforeach
                            @unless($preview)
                                @error('decision.'.$item->id)<div class="banner bad xs" role="alert">{{ $message }}</div>@enderror
                                <div class="field">
                                    <label for="portal-comment-{{ $item->id }}">Comment on version {{ $version->version }}</label>
                                    <textarea id="portal-comment-{{ $item->id }}" class="input" rows="3" wire:model="comments.{{ $item->id }}" placeholder="Optional for approval, required for changes"></textarea>
                                    @error('comments.'.$item->id)<span class="err" role="alert">{{ $message }}</span>@enderror
                                </div>
                                <div class="row">
                                    <button type="button" class="pill pri" wire:click="approve({{ $item->id }})" wire:loading.attr="disabled">Approve version {{ $version->version }}</button>
                                    <button type="button" class="pill ghost" wire:click="requestChanges({{ $item->id }})" wire:loading.attr="disabled">Request changes</button>
                                </div>
                            @endunless
                            <span class="muted xs">A decision applies to version {{ $version->version }} only. Approval does not publish anything.</span>
                        </div>
                    </div>
                </section>
            @endforeach

            <section class="card col" aria-labelledby="portal-approved">
                <h2 id="portal-approved" class="eyebrow">Approved deliverables</h2>
                @forelse($approved as $item)
                    <div class="li" wire:key="portal-approved-{{ $item->id }}">
                        <span class="portal-thumb">@include('content.thumb', ['item' => $item, 'height' => 52, 'imageUrl' => $item->banner_creative_id ? route('portal.image', $item) : null])</span>
                        <span class="grow small"><strong class="medium">{{ $item->title }}</strong> · version {{ $item->current_version }}<br><span class="muted xs">{{ $item->product->name }}@if($item->latestVersion?->reviewed_by_name) · approved by {{ $item->latestVersion->reviewed_by_name }}@endif</span></span>
                        @if($item->banner_creative_id)
                            <a class="pill ghost sm" href="{{ route('portal.image', [$item, 'download' => 1]) }}">Download</a>
                        @else
                            <details class="portal-details"><summary class="pill ghost sm">Read</summary><div class="client-preview col">@include('content.preview', ['item' => $item, 'body' => $item->latestVersion?->body ?? []])</div></details>
                        @endif
                    </div>
                @empty
                    <p class="muted small">{{ $workspace->name }} is preparing your first deliverables.</p>
                @endforelse
                @foreach($pages as $page)
                    <div class="li" wire:key="portal-page-{{ $page->id }}">
                        <span class="ic neu"><i class="ti ti-link" aria-hidden="true"></i></span>
                        <span class="grow small"><strong class="medium">{{ $page->name }}</strong> · version {{ $page->live_version }}<br><span class="muted xs portal-url">{{ $page->product->name }} · live{{ $page->verified_at ? ', verified '.$page->verified_at->format('j M, H:i') : '' }}</span></span>
                        <a class="pill ghost sm" href="{{ $page->remote_url }}" target="_blank" rel="noopener">Open page</a>
                    </div>
                @endforeach
            </section>
        @endif
        <p class="muted xs">Clients never see generation costs, drafts, internal knowledge or other brands.</p>
    </main>
</div>
