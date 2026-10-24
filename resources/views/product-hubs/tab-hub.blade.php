@php
    $latestPack = $packs->first();
    $packItems = ($contentItems && $latestPack)
        ? $contentItems->where('campaign_pack_id', $latestPack->id)->filter(fn ($item) => $item->section && ! str_starts_with($item->section, 'banner.'))
        : collect();
    $approvedItems = $packItems->where('status', 'approved')->count();
    $inReview = $contentItems ? $contentItems->where('status', 'review')->count() : 0;
    $approvedHistory = $packs->flatMap(fn ($pack) => $pack->versions->where('review_status', 'approved')->map(fn ($item) => ['pack' => $pack, 'version' => $item]))
        ->sortByDesc(fn ($row) => $row['version']->reviewed_at ?? $row['version']->updated_at)->values();
@endphp

@if(! $version)
    <section class="card col hub-setup" aria-labelledby="hub-setup-title">
        <span class="eyebrow">Client hub</span>
        <h2 id="hub-setup-title" class="display hub-h">Nothing to share yet</h2>
        <p class="muted">The client hub is one page your client can open with a link: approved copy for every channel, images and your files. It only ever shows approved work, so it fills in once a launch pack is approved.</p>
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
                @if($latestPack)<a class="pill ghost sm" href="{{ $inReview ? route('review.index') : route('campaign-packs.show', $latestPack) }}" wire:navigate>{{ $inReview ? 'Open review queue' : 'Open launch pack' }}</a>@endif
            </li>
            <li>
                <span class="hub-step-n" aria-hidden="true">3</span>
                <span class="grow"><strong class="medium">Share the link with your client</strong><br><span class="muted small">They can copy lines and download images. No sign-in needed, and you can turn the link off at any time.</span></span>
            </li>
        </ol>
    </section>
@else
    <section class="card col hub-share" aria-labelledby="hub-share-title">
        <div class="row hub-share-head">
            <div class="grow col hub-tight">
                <span class="eyebrow">Client hub</span>
                <h2 id="hub-share-title" class="display hub-h">{{ $share ? 'Shared with your client' : 'Ready to share' }}</h2>
                <p class="muted small">One page with this product's approved copy, images and files. Drafts, costs and internal notes never appear on it.</p>
            </div>
            @if($canManage)
                <div class="row hub-actions">
                    @if($share)
                        <button type="button" class="pill pri" x-data="{ done: false }" @click="navigator.clipboard.writeText(@js($shareUrl)).then(() => { done = true; setTimeout(() => done = false, 1600) })"><i class="ti" :class="done ? 'ti-check' : 'ti-link'" aria-hidden="true"></i><span x-text="done ? 'Link copied' : 'Copy client link'">Copy client link</span></button>
                        <a class="pill ghost" href="{{ $shareUrl }}" target="_blank" rel="noopener">Open as client <i class="ti ti-external-link" aria-hidden="true"></i></a>
                    @else
                        <button type="button" class="pill pri" wire:click="createShare" wire:loading.attr="disabled" wire:target="createShare"><i class="ti ti-link" aria-hidden="true"></i>Create client link</button>
                    @endif
                </div>
            @endif
        </div>

        <div class="col hub-status">
            <div class="kv"><span>Showing</span><span>{{ $version->campaignPack->name }} · version {{ $version->version }}, approved {{ ($version->reviewed_at ?? $version->updated_at)->format('j M Y') }}</span></div>
            @if($inReview)
                <div class="kv"><span>Newer work</span><span>{{ trans_choice(':count output is in review.|:count outputs are in review.', $inReview) }} The hub updates when the whole pack is approved. <a href="{{ route('review.index') }}" wire:navigate>Review</a></span></div>
            @endif
            <div class="kv"><span>Client link</span>
                <span>
                    @if($share)
                        <span class="break">{{ $shareUrl }}</span> · created {{ $share->created_at->format('j M Y') }}
                        @if($canManage) · <button type="button" class="linkbtn" wire:click="revokeShare" wire:confirm="Turn off this link? Anyone who has it will no longer be able to open the hub.">Turn off link</button>@endif
                    @else
                        Off. Nobody outside your team can see this hub.
                    @endif
                </span>
            </div>
            <div class="kv"><span>Client accounts</span><span>Clients you invite in <a href="{{ route('team.index') }}" wire:navigate>Team</a> see this in their <a href="{{ route('portal.index') }}" wire:navigate>client view</a> too.</span></div>
        </div>

        @if($canManage)
            <details class="hub-edit" @if($errors->has('resourceInputs.*') || session('resource-status')) open @endif>
                <summary class="pill ghost sm">Files and folders · {{ $product->resourceLinks->count() }}</summary>
                <form wire:submit="saveResources" class="col hub-form">
                    <p class="muted small">Links to your own files: brand guide, Drive folders, the product page. They show as buttons at the top of the hub.</p>
                    @if(session('resource-status'))<div class="banner ok" role="status"><i class="ti ti-circle-check" aria-hidden="true"></i><span>{{ session('resource-status') }}</span></div>@endif
                    <div class="hub-form-grid">
                        @foreach($resourceLabels as $kind => $label)
                            <div class="field">
                                <label for="resource-{{ $kind }}">{{ $label }}</label>
                                <input id="resource-{{ $kind }}" class="input" type="url" wire:model="resourceInputs.{{ $kind }}" placeholder="https://">
                                @error('resourceInputs.'.$kind)<span class="err" role="alert">{{ $message }}</span>@enderror
                            </div>
                        @endforeach
                    </div>
                    <div class="row"><button type="submit" class="pill dark sm">Save links</button></div>
                </form>
            </details>
        @endif
    </section>

    <div class="row hub-preview-label">
        <span class="eyebrow grow">Preview · what your client sees</span>
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
