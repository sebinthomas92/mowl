{{--
    One piece of work waiting for a client's decision. Used on the client link and in the client portal.
    Expects $item (ContentItem with latestVersion and versions.comments limited to client comments),
    $imageUrl (?string), $canDecide (bool) and optionally $showProduct (bool).
--}}
@php
    $version = $item->latestVersion;
    $previous = $item->versions->firstWhere('version', $version->version - 1);
@endphp
<section class="card col" wire:key="decision-{{ $item->id }}" aria-labelledby="decision-{{ $item->id }}">
    <div class="client-item">
        <div class="client-preview col">@include('content.preview', ['item' => $item, 'body' => $version->body, 'imageUrl' => $imageUrl])</div>
        <div class="col">
            <div class="row">
                <div class="grow"><h3 id="decision-{{ $item->id }}" class="medium portal-title">{{ $item->title }}</h3>@if($showProduct ?? false)<span class="muted xs">{{ $item->product->name }}</span>@endif</div>
                <span class="tag rev"><span class="dot"></span>Version {{ $version->version }}</span>
            </div>
            @if($previous)
                <div class="small"><span class="muted">What changed since version {{ $previous->version }}</span><br>{{ $version->note ?: 'A revised version.' }}</div>
            @endif
            @foreach($item->versions->sortBy('version') as $earlier)
                @foreach($earlier->comments as $comment)
                    <div class="msg portal-note">
                        <span class="av" aria-hidden="true">{{ mb_strtoupper(mb_substr($comment->author_name, 0, 1)) }}</span>
                        <span><strong class="medium">{{ $comment->user_id && $comment->user_id === auth()->id() ? 'You' : $comment->author_name }}</strong> <span class="muted xs">on version {{ $earlier->version }}</span><br>{{ $comment->body }}</span>
                    </div>
                @endforeach
            @endforeach
            @if($canDecide)
                @error('decision.'.$item->id)<div class="banner bad xs" role="alert">{{ $message }}</div>@enderror
                <div class="field">
                    <label for="decision-comment-{{ $item->id }}">Comment on version {{ $version->version }}</label>
                    <textarea id="decision-comment-{{ $item->id }}" class="input" rows="3" wire:model="comments.{{ $item->id }}" placeholder="Optional when approving, required when asking for changes"></textarea>
                    @error('comments.'.$item->id)<span class="err" role="alert">{{ $message }}</span>@enderror
                </div>
                <div class="row">
                    <button type="button" class="pill pri" wire:click="approve({{ $item->id }})" wire:loading.attr="disabled">Approve version {{ $version->version }}</button>
                    <button type="button" class="pill ghost" wire:click="requestChanges({{ $item->id }})" wire:loading.attr="disabled">Request changes</button>
                </div>
                <span class="muted xs">A decision applies to version {{ $version->version }} only. Approving does not publish anything.</span>
            @endif
        </div>
    </div>
</section>
