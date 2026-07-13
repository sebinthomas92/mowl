@php($creditBalance = $workspace->creditBalance())
@php($monthlyCredits = config('campaigns.monthly_credits'))
@php($creditsUsed = max(0, $monthlyCredits - $creditBalance))
<aside class="sidebar" :class="mobileNav && 'is-open'" aria-label="Main navigation">
    <button type="button" class="sidebar-close" @click="mobileNav = false" aria-label="Close navigation">×</button>
    <a href="{{ route('campaign-packs.index') }}" class="brand-lockup">
        <img src="/marketing-owl-logo.png" alt="" class="owl-mark">
        <div><strong>Marketing</strong><strong>Owl</strong></div>
    </a>

    @php($userWorkspaces = auth()->user()->workspaces)
    @php($membership = $userWorkspaces->firstWhere('id', $workspace->id))
    <details class="workspace-switcher" @if($userWorkspaces->count() < 2) data-single-workspace @endif>
        <summary aria-label="Select workspace">
            <span class="workspace-monogram">{{ strtoupper(substr($workspace->name, 0, 2)) }}</span>
            <span><strong>{{ $workspace->name }}</strong><small>{{ ucfirst($membership->pivot->role) }} workspace</small></span>
            @if($userWorkspaces->count() > 1)<span class="chevron" aria-hidden="true">⌄</span>@endif
        </summary>
        @if($userWorkspaces->count() > 1)
            <div class="workspace-options">
                @foreach($userWorkspaces as $option)
                    <form method="POST" action="{{ route('workspaces.select', $option) }}">
                        @csrf
                        <button type="submit" @class(['active' => $option->id === $workspace->id])>{{ $option->name }}</button>
                    </form>
                @endforeach
            </div>
        @endif
    </details>

    <nav class="primary-nav" aria-label="Workspace sections">
        <a href="{{ route('brands.index') }}" @class(['active' => request()->routeIs('brands.*')])><span>◇</span> Brands</a>
        <a href="{{ route('products.index') }}" @class(['active' => request()->routeIs('products.*')])><span>▱</span> Products</a>
        <a href="{{ route('campaign-packs.index') }}" @class(['active' => request()->routeIs('campaign-packs.*')])><span>▤</span> Campaign packs</a>
        <a href="{{ route('team.index') }}" @class(['active' => request()->routeIs('team.*')])><span>◎</span> Team</a>
        <a href="{{ route('usage.index') }}" @class(['active' => request()->routeIs('usage.*')])><span>⌁</span> Usage & cost</a>
        <a href="{{ route('workspace.settings') }}" @class(['active' => request()->routeIs('workspace.settings')])><span>◌</span> Settings</a>
        @if(in_array(strtolower(auth()->user()->email), array_map('strtolower', config('campaigns.concierge_emails')), true))
            <a href="{{ route('concierge.index') }}" @class(['active' => request()->routeIs('concierge.*')])><span>◈</span> Concierge</a>
        @endif
    </nav>

    <div class="nav-label"><span>Workspace</span><i></i></div>
    <div class="workspace-usage">
        <div><span>Pack credits</span><strong>{{ $creditBalance }}</strong></div>
        <div class="usage-meter"><i style="width: {{ min(100, ($creditsUsed / max(1, $monthlyCredits)) * 100) }}%"></i></div>
        <small>{{ $creditsUsed }} used this cycle</small>
    </div>

    <div class="sidebar-footer">
        <div class="signed-in-user"><span>{{ strtoupper(substr(auth()->user()->name, 0, 1)) }}</span><div><strong>{{ auth()->user()->name }}</strong><small>{{ auth()->user()->email }}</small></div></div>
        <form method="POST" action="{{ route('logout') }}">@csrf<button type="submit"><span>↗</span> Sign out</button></form>
    </div>
</aside>
