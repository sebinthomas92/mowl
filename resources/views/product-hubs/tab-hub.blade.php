@php
    $latestPack = $packs->first();
    $packItems = ($contentItems && $latestPack)
        ? $contentItems->where('campaign_pack_id', $latestPack->id)->filter(fn ($item) => $item->section && ! str_starts_with($item->section, 'banner.'))
        : collect();
    $approvedItems = $packItems->where('status', 'approved')->count();
    $inReview = $contentItems ? $contentItems->where('status', 'review')->count() : 0;
    $approvedHistory = $packs->flatMap(fn ($pack) => $pack->versions->where('review_status', 'approved')->map(fn ($item) => ['pack' => $pack, 'version' => $item]))
        ->sortByDesc(fn ($row) => $row['version']->reviewed_at ?? $row['version']->updated_at)->values();
    // A client can approve through the link only when the person who made it can approve work themselves.
    $linkMaker = $share ? \App\Models\User::find($share->created_by_user_id) : auth()->user();
    $clientCanDecide = $linkMaker && (bool) \App\Support\Membership::of($linkMaker, $product->brand->workspace_id)?->canReview();
@endphp

@if($latestPack || $version)
    @include('product-hubs.hub-share-card')
@endif

@if(! $version)
    <section class="card col hub-setup" aria-labelledby="hub-setup-title">
        <span class="eyebrow">{{ $agency ? 'Client hub' : 'Ready to use' }}</span>
        <h2 id="hub-setup-title" class="display hub-h">{{ $latestPack ? 'Not ready yet' : 'Nothing to share yet' }}</h2>
        <p class="muted">{{ $agency ? 'The client hub is the page your client gets' : 'This is the page you and anyone you share it with use' }}: approved copy for every channel, images and your files. It fills in once every output of a launch pack is approved.</p>
        <ol class="hub-steps">
            <li @class(['done' => (bool) $latestPack, 'cur' => ! $latestPack])>
                <span class="hub-step-n" aria-hidden="true">{{ $latestPack ? '' : '1' }}</span>
                <span class="grow"><strong class="medium">Create a launch pack</strong><br><span class="muted small">{{ $latestPack ? 'Done: '.$latestPack->name : 'It writes channel copy from this product\'s confirmed facts.' }}</span></span>
                @if(! $latestPack && $canManage)<a class="pill pri sm" href="{{ route('campaign-packs.create', ['product' => $product->id]) }}" wire:navigate>Create a launch pack</a>@endif
            </li>
            <li @class(['cur' => (bool) $latestPack])>
                <span class="hub-step-n" aria-hidden="true">2</span>
                <span class="grow"><strong class="medium">Approve each output</strong><br><span class="muted small">
                    @if($packItems->isNotEmpty()){{ $approvedItems }} of {{ $packItems->count() }} outputs approved. The hub fills in when all are approved.@else Every output of the pack is reviewed on its own.@endif
                </span></span>
                @if($latestPack)<a class="pill ghost sm" href="{{ $inReview ? route('review.index') : route('campaign-packs.show', $latestPack) }}" wire:navigate>{{ $inReview ? 'Open Review' : 'Open launch pack' }}</a>@endif
            </li>
            <li>
                <span class="hub-step-n" aria-hidden="true">3</span>
                <span class="grow"><strong class="medium">{{ $agency ? 'Share the link with your client' : 'Copy, download or share' }}</strong><br><span class="muted small">{{ $agency ? 'One link for everything: they approve work in review, then copy lines and download images. No sign-in needed.' : 'Copy lines and download images here, or share a link with a teammate or freelancer. No sign-in needed.' }}</span></span>
            </li>
        </ol>
    </section>
@else
    <div class="row hub-preview-label">
        <span class="eyebrow grow">{{ $agency ? 'Preview · what your client sees' : 'Ready to use · what people with the link see' }}</span>
        @if($approvedHistory->count() > 1)
            <details class="hub-history">
                <summary class="linkbtn">Approval history · {{ $approvedHistory->count() }}</summary>
                <div class="menu menu-end" role="list">
                    @foreach($approvedHistory as $row)
                        <a role="listitem" href="{{ route('campaign-packs.show', $row['pack']) }}" wire:navigate><i class="ti ti-circle-check" aria-hidden="true"></i><span class="grow">{{ $row['pack']->name }} · version {{ $row['version']->version }}<br><span class="muted xs">Approved {{ optional($row['version']->reviewed_at)->format('j M Y') ?: 'date unknown' }}</span></span></a>
                    @endforeach
                </div>
            </details>
        @endif
    </div>
    @include('client-hub.body', ['hub' => $hub])
@endif
