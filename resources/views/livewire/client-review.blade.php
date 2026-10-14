<div class="client-page">
    <header class="client-head">
        <strong class="medium">{{ $product?->brand?->name ?? 'Marketing Owl' }}</strong>
        <span class="muted small">Review for {{ $link->client_name }}</span>
        <span class="sp"></span>
        <span class="muted xs">Link expires {{ $link->expires_at->format('j M Y') }}</span>
    </header>
    <main class="client-main" id="main-content">
        <div>
            <div class="eyebrow">Client review</div>
            <h1 class="display">{{ $product?->name }}</h1>
            <p class="muted">Approve each item or tell the team what should change. Approving marks that exact version as usable; nothing is published from this page.</p>
        </div>

        @if($flash)<div class="banner ok" role="status"><i class="ti ti-circle-check" aria-hidden="true"></i><span>{{ $flash }}</span></div>@endif

        <section class="col" aria-labelledby="client-pending">
            <h2 id="client-pending" class="eyebrow">Waiting for your decision · {{ $pending->count() }}</h2>
            @forelse($pending as $item)
                @php($version = $item->latestVersion)
                @php($imageUrl = $item->banner_creative_id ? route('client.review-banner', [$token, $item->id]) : null)
                <article class="card client-item" wire:key="client-item-{{ $item->id }}">
                    <div class="client-preview col">@include('content.preview', ['item' => $item, 'body' => $version->body, 'imageUrl' => $imageUrl])</div>
                    <div class="col">
                        <div class="row"><strong class="medium grow">{{ $item->title }}</strong><span class="tag">v{{ $version->version }}</span></div>
                        @foreach($version->comments->sortBy('id') as $comment)
                            @include('content.comment', ['comment' => $comment, 'version' => $version])
                        @endforeach
                        @error('decision.'.$item->id)<div class="banner bad xs" role="alert">{{ $message }}</div>@enderror
                        <div class="field">
                            <label for="client-comment-{{ $item->id }}">Your notes on v{{ $version->version }}</label>
                            <textarea id="client-comment-{{ $item->id }}" class="input" rows="3" wire:model="comments.{{ $item->id }}" placeholder="Optional when approving, required when asking for changes"></textarea>
                            @error('comments.'.$item->id)<span class="err" role="alert">{{ $message }}</span>@enderror
                        </div>
                        <div class="row">
                            <button type="button" class="pill dark sm" wire:click="approve({{ $item->id }})" wire:loading.attr="disabled">Approve v{{ $version->version }}</button>
                            <button type="button" class="pill ghost sm" wire:click="requestChanges({{ $item->id }})" wire:loading.attr="disabled">Request changes</button>
                        </div>
                    </div>
                </article>
            @empty
                <div class="empty"><strong>Nothing is waiting for you</strong><span class="muted small">The team will send new versions here when they are ready.</span></div>
            @endforelse
        </section>

        @if($approved->isNotEmpty())
            <section class="col" aria-labelledby="client-approved">
                <h2 id="client-approved" class="eyebrow">Approved deliverables · {{ $approved->count() }}</h2>
                @foreach($approved as $item)
                    <div class="li small" wire:key="client-approved-{{ $item->id }}">
                        <span class="grow"><strong class="medium">{{ $item->title }}</strong> v{{ $item->current_version }}@if($item->latestVersion?->reviewed_by_name)<br><span class="muted xs">Approved by {{ $item->latestVersion->reviewed_by_name }}</span>@endif</span>
                        @include('content.status-tag', ['status' => 'approved'])
                    </div>
                @endforeach
            </section>
        @endif
        <p class="xs muted">Clients never see generation costs, drafts or internal notes on earlier versions.</p>
    </main>
</div>
