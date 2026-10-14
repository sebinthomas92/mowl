@php($sideMembership = \App\Support\Membership::of(auth()->user(), $workspace))
@php($creditBalance = $workspace->creditBalance())
@php($monthlyCredits = config('campaigns.monthly_credits'))
@php($creditsUsed = max(0, $monthlyCredits - $creditBalance))
@php($userWorkspaces = auth()->user()->workspaces)
@php($isConcierge = in_array(strtolower(auth()->user()->email), array_map('strtolower', config('campaigns.concierge_emails')), true))
@php($navLink = fn (string $route, string $pattern, string $label, string $icon, bool $sub = false) => [
    'href' => route($route), 'label' => $label, 'icon' => $icon, 'sub' => $sub,
    'current' => request()->routeIs($pattern),
])
@php($primary = [
    $navLink('products.index', 'products.*', 'Products', 'package'),
    $navLink('campaign-packs.index', 'campaign-packs.*', 'Launch packs', 'stack-2', true),
    $navLink('brands.index', 'brands.*', 'Brands', 'building-store'),
    $navLink('library.index', 'library.*', 'Library', 'layout-grid'),
    $navLink('campaigns.index', 'campaigns.*', 'Campaigns', 'speakerphone'),
    $navLink('workflows.index', 'workflows.*', 'Workflows', 'sparkles'),
])
@php($secondary = array_filter([
    $navLink('team.index', 'team.*', 'Team', 'users'),
    $sideMembership?->canSeeCosts() ? $navLink('usage.index', 'usage.*', 'Usage', 'chart-bar') : null,
    $navLink('workspace.settings', 'workspace.settings', 'Settings', 'settings'),
    $navLink('help.index', 'help.*', 'Help', 'help-circle'),
    $isConcierge ? $navLink('concierge.index', 'concierge.*', 'Concierge', 'lifebuoy') : null,
]))
<aside class="side" aria-label="Main navigation">
    <a href="{{ route('products.index') }}" class="side-logo" aria-label="Marketing Owl home">
        <svg width="30" height="20" viewBox="0 0 30 20" aria-hidden="true"><circle cx="9" cy="10" r="8" fill="#FF5A3D"/><circle cx="21" cy="10" r="8" fill="#EA38D0" opacity=".85"/><circle cx="9" cy="10" r="2.5" fill="#fff"/><circle cx="21" cy="10" r="2.5" fill="#fff"/></svg>
        <span class="side-label">marketingowl</span>
    </a>

    <details class="ws-switch" @if($userWorkspaces->count() < 2) data-single-workspace @endif>
        <summary class="pill ghost sm" aria-label="Workspace: {{ $workspace->name }}" title="{{ $workspace->name }}">
            <span class="ws-mono" aria-hidden="true">{{ strtoupper(substr($workspace->name, 0, 1)) }}</span>
            <span class="side-label ws-name">{{ $workspace->name }}</span>
            @if($userWorkspaces->count() > 1)<i class="ti ti-chevron-down side-label" aria-hidden="true"></i>@endif
        </summary>
        @if($userWorkspaces->count() > 1)
            <div class="menu" role="menu">
                <div class="mh">Workspaces</div>
                @foreach($userWorkspaces as $option)
                    <form method="POST" action="{{ route('workspaces.select', $option) }}">
                        @csrf
                        <button type="submit" role="menuitem" @if($option->id === $workspace->id) aria-current="true" @endif>
                            <i @class(['ti', 'ti-check' => $option->id === $workspace->id, 'ti-point' => $option->id !== $workspace->id]) aria-hidden="true"></i>{{ $option->name }}
                        </button>
                    </form>
                @endforeach
            </div>
        @endif
    </details>

    <nav aria-label="Workspace sections">
        @foreach($primary as $link)
            <a href="{{ $link['href'] }}" title="{{ $link['label'] }}" @class(['cur' => $link['current'], 'sub' => $link['sub']]) @if($link['current']) aria-current="page" @endif><i class="ti ti-{{ $link['icon'] }}" aria-hidden="true"></i><span class="side-label">{{ $link['label'] }}</span></a>
        @endforeach
        <div class="sep" role="separator"></div>
        @foreach($secondary as $link)
            <a href="{{ $link['href'] }}" title="{{ $link['label'] }}" @class(['cur' => $link['current']]) @if($link['current']) aria-current="page" @endif><i class="ti ti-{{ $link['icon'] }}" aria-hidden="true"></i><span class="side-label">{{ $link['label'] }}</span></a>
        @endforeach
    </nav>

    @if($sideMembership?->canSeeCosts())
    <div class="note">
        <div class="note-row"><span>Pack credits</span><strong class="num">{{ $creditBalance }} left</strong></div>
        <div class="meter" role="meter" aria-label="Pack credits used this cycle" aria-valuemin="0" aria-valuemax="{{ $monthlyCredits }}" aria-valuenow="{{ $creditsUsed }}"><i style="width: {{ min(100, ($creditsUsed / max(1, $monthlyCredits)) * 100) }}%"></i></div>
        <span>{{ $creditsUsed }} of {{ $monthlyCredits }} used this cycle</span>
    </div>
    @endif
</aside>
