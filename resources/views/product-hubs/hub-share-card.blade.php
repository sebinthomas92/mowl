{{-- The one client link for a product: work waiting for the client's decision, then the client hub. --}}
<section class="card col hub-share" aria-labelledby="hub-share-title">
    <div class="row hub-share-head">
        <div class="grow col hub-tight">
            <span class="eyebrow">{{ $agency ? 'Client link' : 'Share link' }}</span>
            <h2 id="hub-share-title" class="display hub-h">{{ $agency ? ($share ? 'Shared with your client' : 'Share with your client') : ($share ? 'Shared' : 'Share this work') }}</h2>
            <p class="muted small">@if($agency)One link, no sign-in: your client approves work waiting for review, then copies approved lines and downloads images.@else One link, no sign-in, for a teammate, freelancer or anyone helping you launch: they copy approved lines and download images, and can approve work waiting for review.@endif Drafts, costs and internal notes never appear on it.</p>
        </div>
        @if($canManage)
            <div class="row hub-actions">
                @if($share)
                    <button type="button" class="pill pri" x-data="{ done: false }" @click="navigator.clipboard.writeText(@js($shareUrl)).then(() => { done = true; setTimeout(() => done = false, 1600) })"><i class="ti" :class="done ? 'ti-check' : 'ti-link'" aria-hidden="true"></i><span x-text="done ? 'Link copied' : @js($agency ? 'Copy client link' : 'Copy link')">{{ $agency ? 'Copy client link' : 'Copy link' }}</span></button>
                    <a class="pill ghost" href="{{ $shareUrl }}" target="_blank" rel="noopener">{{ $agency ? 'Open as client' : 'Open link' }} <i class="ti ti-external-link" aria-hidden="true"></i></a>
                @else
                    <button type="button" class="pill pri" wire:click="createShare" wire:loading.attr="disabled" wire:target="createShare"><i class="ti ti-link" aria-hidden="true"></i>{{ $agency ? 'Create client link' : 'Create share link' }}</button>
                @endif
            </div>
        @endif
    </div>

    <div class="col hub-status">
        <div class="kv"><span>Ready to use</span><span>
            @if($version)
                {{ $version->campaignPack->name }} · version {{ $version->version }}, approved {{ ($version->reviewed_at ?? $version->updated_at)->format('j M Y') }}
            @else
                Nothing yet. This fills in when every output of a launch pack is approved. @if($packItems->isNotEmpty())So far {{ $approvedItems }} of {{ $packItems->count() }} are approved.@endif
            @endif
        </span></div>
        <div class="kv"><span>Waiting for review</span><span>
            @if($inReview)
                {{ trans_choice(':count output is in review.|:count outputs are in review.', $inReview) }}
                @if($clientCanDecide) {{ $agency ? 'Your client' : 'Anyone with the link' }} can approve or comment there, or your team can in <a href="{{ route('review.index') }}" wire:navigate>Review</a>.
                @else Your team decides in <a href="{{ route('review.index') }}" wire:navigate>Review</a>.@endif
            @else
                Nothing right now.
            @endif
        </span></div>
        @unless($clientCanDecide)
            <div class="kv"><span>Approving</span><span>Off. {{ $share ? 'This link was made by someone who can\'t approve work, so it is view only.' : 'You can\'t approve work yourself, so a link you make is view only.' }} An owner or reviewer can make a link that allows approving.</span></div>
        @endunless
        <div class="kv"><span>{{ $agency ? 'Client link' : 'Link' }}</span>
            <span>
                @if($share)
                    <span class="break">{{ $shareUrl }}</span> · created {{ $share->created_at->format('j M Y') }}
                    @if($canManage) · <button type="button" class="linkbtn" wire:click="revokeShare" wire:confirm="Turn off this link? Anyone who has it will no longer be able to open it.">Turn off link</button>@endif
                @else
                    Off. Nobody outside your team can see this work.
                @endif
            </span>
        </div>
        @if($agency)<div class="kv"><span>Client portal</span><span>Clients you invite in <a href="{{ route('team.index') }}" wire:navigate>Team</a> see the same page when they sign in. <a href="{{ route('portal.index', array_filter(['brand' => $product->brand_id, 'product' => $version ? $product->id : null])) }}" wire:navigate>Preview it</a></span></div>@endif
    </div>

    @if($canManage)
        <details class="hub-edit" @if($errors->has('resourceInputs.*') || session('resource-status')) open @endif>
            <summary class="pill ghost sm">Files and folders · {{ $product->resourceLinks->count() }}</summary>
            <form wire:submit="saveResources" class="col hub-form">
                <p class="muted small">Links to your own files: brand guide, Drive folders, the product page. They show as buttons at the top of the shared page.</p>
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
