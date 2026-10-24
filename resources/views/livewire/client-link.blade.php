<div class="client-page">
    <a class="skip-link" href="#main-content">Skip to the work</a>
    <header class="client-head">
        <svg width="30" height="20" viewBox="0 0 30 20" aria-hidden="true"><circle cx="9" cy="10" r="8" fill="#FF5A3D"/><circle cx="21" cy="10" r="8" fill="#EA38D0" opacity=".85"/><circle cx="9" cy="10" r="2.5" fill="#fff"/><circle cx="21" cy="10" r="2.5" fill="#fff"/></svg>
        <div class="grow">
            <strong class="medium">{{ $product->brand->name }} · {{ $workspace->isAgency() ? 'client hub' : 'ready to use' }}</strong><br>
            <span class="muted xs">Shared by {{ $workspace->name }}@if($kind === 'review') with {{ $link->client_name }} · link expires {{ $link->expires_at->format('j M Y') }}@endif</span>
        </div>
        @unless($canDecide)<span class="tag"><i class="ti ti-lock" aria-hidden="true"></i>View only</span>@endunless
    </header>

    <main class="client-main" id="main-content">
        @if($flash)<div class="banner ok" role="status"><i class="ti ti-circle-check" aria-hidden="true"></i><span>{{ $flash }}</span></div>@endif

        @if($canDecide && $pending->isNotEmpty())
            <section class="col" aria-labelledby="client-pending">
                <div>
                    <h2 id="client-pending" class="eyebrow">Waiting for your decision · {{ $pending->count() }}</h2>
                    <p class="muted small">Approve each piece or say what should change.</p>
                </div>
                @if($kind === 'hub')
                    <div class="field client-name">
                        <label for="client-reviewer">Your name</label>
                        <input id="client-reviewer" class="input" wire:model="reviewerName" maxlength="120" autocomplete="name" placeholder="So the team knows who decided">
                        @error('reviewerName')<span class="err" role="alert">{{ $message }}</span>@enderror
                    </div>
                @endif
                @foreach($pending as $item)
                    @include('client-hub.decision', ['item' => $item, 'imageUrl' => $imageUrl($item), 'canDecide' => true])
                @endforeach
            </section>
        @endif

        @if($hub)
            <section class="col" aria-label="Ready to use">
                @if($canDecide && $pending->isNotEmpty())<h2 class="eyebrow">Ready to use</h2>@endif
                @include('client-hub.body', ['product' => $product, 'hub' => $hub, 'heading' => 'h1'])
            </section>
        @else
            <div>
                <div class="eyebrow">{{ $workspace->isAgency() ? 'Client hub' : 'Ready to use' }}</div>
                <h1 class="display">{{ $product->name }}</h1>
            </div>
            @if($approved->isNotEmpty())
                <section class="card col" aria-labelledby="client-approved">
                    <h2 id="client-approved" class="eyebrow">Approved so far · {{ $approved->count() }}</h2>
                    <p class="muted small">Copy for every channel appears here once all of the launch pack is approved.</p>
                    @foreach($approved as $item)
                        <div class="li small" wire:key="client-approved-{{ $item->id }}">
                            <span class="grow"><strong class="medium">{{ $item->title }}</strong> · version {{ $item->current_version }}@if($item->latestVersion?->reviewed_by_name)<br><span class="muted xs">Approved by {{ $item->latestVersion->reviewed_by_name }}</span>@endif</span>
                            @include('content.status-tag', ['status' => 'approved'])
                        </div>
                    @endforeach
                </section>
            @elseif(! ($canDecide && $pending->isNotEmpty()))
                <div class="empty"><strong>Nothing is ready yet</strong><span class="muted small">{{ $workspace->name }} will share work here as soon as it is ready.</span></div>
            @endif
        @endif

        <p class="muted xs">This page only shows work that is ready for you. Drafts, costs and internal notes never appear here.</p>
    </main>
</div>
