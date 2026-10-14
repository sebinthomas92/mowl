@php($membership = \App\Support\Membership::of(auth()->user(), $workspace))
@php($contentReview = \App\Services\ContentLibrary::available())
@php($reviewCount = $contentReview
    ? $membership->scopeThroughProduct(\App\Models\ContentItem::query())->where('workspace_id', $workspace->id)->where('status', 'review')->count()
    : $membership->scopeThroughProduct(\App\Models\CampaignPack::query())
        ->where('status', 'review')
        ->whereHas('product.brand', fn ($query) => $query->where('workspace_id', $workspace->id))
        ->count())
@php($user = auth()->user())
<header class="top">
    <livewire:global-search />
    <a class="pill ghost sm" href="{{ $contentReview ? route('review.index') : route('campaign-packs.index', ['status' => 'review']) }}" aria-label="Review queue, {{ $reviewCount }} waiting">Review queue <span class="count num">{{ $reviewCount }}</span></a>
    <a class="pill ghost sm top-client" href="{{ route('portal.index') }}" wire:navigate title="See what a client of a brand sees">Client view</a>
    <livewire:inbox-drawer />
    <details class="account">
        <summary class="avatar" aria-label="Account menu for {{ $user->name }}" title="{{ $user->name }}">{{ collect(explode(' ', $user->name))->filter()->take(2)->map(fn ($part) => mb_strtoupper(mb_substr($part, 0, 1)))->implode('') }}</summary>
        <div class="menu menu-end" role="menu">
            <div class="mh"><strong>{{ $user->name }}</strong><br>{{ $user->email }}<br>{{ $membership->roleLabel() }}{{ $membership->brandId ? ' · '.($workspace->brands()->find($membership->brandId)?->name ?? 'one brand') : '' }}</div>
            <form method="POST" action="{{ route('logout') }}">@csrf<button type="submit" role="menuitem"><i class="ti ti-logout" aria-hidden="true"></i>Sign out</button></form>
        </div>
    </details>
</header>
