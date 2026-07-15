@php($creditBalance = $workspace->creditBalance())
@php($monthlyCredits = config('campaigns.monthly_credits'))
@php($creditsUsed = max(0, $monthlyCredits - $creditBalance))
<aside
    class="sidebar"
    x-data="{
        sidebarPinned: false,
        sidebarPeek: false,
        init() {
            try {
                this.sidebarPinned = localStorage.getItem('marketing-owl.sidebar-pinned') === 'true';
                this.sidebarPeek = this.sidebarPinned;
            } catch (error) {}
        },
        togglePin() {
            this.sidebarPinned = !this.sidebarPinned;
            this.sidebarPeek = this.sidebarPinned;
            try {
                localStorage.setItem('marketing-owl.sidebar-pinned', this.sidebarPinned.toString());
            } catch (error) {}
        }
    }"
    :class="{ 'is-open': mobileNav, 'is-expanded': sidebarPinned || sidebarPeek, 'is-pinned': sidebarPinned }"
    :data-sidebar-pinned="sidebarPinned.toString()"
    @mouseenter="sidebarPeek = true"
    @mouseleave="sidebarPeek = sidebarPinned"
    @focusin="sidebarPeek = true"
    @focusout="if (!$el.contains($event.relatedTarget)) sidebarPeek = sidebarPinned"
    @keydown.escape.stop="sidebarPeek = sidebarPinned"
    aria-label="Main navigation"
>
    <button type="button" class="sidebar-close" @click="mobileNav = false" aria-label="Close navigation">×</button>
    <button
        type="button"
        class="sidebar-pin"
        @click="togglePin()"
        :aria-label="sidebarPinned ? 'Unpin navigation' : 'Pin navigation'"
        :title="sidebarPinned ? 'Unpin navigation' : 'Pin navigation'"
        :aria-pressed="sidebarPinned.toString()"
    ><i class="ti ti-pin" aria-hidden="true"></i></button>
    <a href="{{ route('campaign-packs.index') }}" class="brand-lockup" aria-label="Marketing Owl home" title="Marketing Owl">
        <img src="/marketing-owl-logo.png" alt="" class="owl-mark">
        <div class="sidebar-copy"><strong>Marketing</strong><strong>Owl</strong></div>
    </a>

    @php($userWorkspaces = auth()->user()->workspaces)
    @php($membership = $userWorkspaces->firstWhere('id', $workspace->id))
    <details class="workspace-switcher" @if($userWorkspaces->count() < 2) data-single-workspace @endif>
        <summary aria-label="Select workspace">
            <span class="workspace-monogram">{{ strtoupper(substr($workspace->name, 0, 2)) }}</span>
            <span class="sidebar-copy"><strong>{{ $workspace->name }}</strong><small>{{ ucfirst($membership->pivot->role) }} workspace</small></span>
            @if($userWorkspaces->count() > 1)<span class="chevron sidebar-copy" aria-hidden="true">⌄</span>@endif
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
        <a href="{{ route('brands.index') }}" aria-label="Brands" title="Brands" @class(['active' => request()->routeIs('brands.*')])><span class="nav-icon" aria-hidden="true">◇</span><span class="sidebar-copy nav-text">Brands</span></a>
        <a href="{{ route('products.index') }}" aria-label="Products" title="Products" @class(['active' => request()->routeIs('products.*')])><span class="nav-icon" aria-hidden="true">▱</span><span class="sidebar-copy nav-text">Products</span></a>
        <a href="{{ route('campaign-packs.index') }}" aria-label="Campaign packs" title="Campaign packs" @class(['active' => request()->routeIs('campaign-packs.*')])><span class="nav-icon" aria-hidden="true">▤</span><span class="sidebar-copy nav-text">Campaign packs</span></a>
        <a href="{{ route('team.index') }}" aria-label="Team" title="Team" @class(['active' => request()->routeIs('team.*')])><span class="nav-icon" aria-hidden="true">◎</span><span class="sidebar-copy nav-text">Team</span></a>
        <a href="{{ route('usage.index') }}" aria-label="Usage and cost" title="Usage & cost" @class(['active' => request()->routeIs('usage.*')])><span class="nav-icon" aria-hidden="true">⌁</span><span class="sidebar-copy nav-text">Usage & cost</span></a>
        <a href="{{ route('workspace.settings') }}" aria-label="Settings" title="Settings" @class(['active' => request()->routeIs('workspace.settings')])><span class="nav-icon" aria-hidden="true">◌</span><span class="sidebar-copy nav-text">Settings</span></a>
        @if(in_array(strtolower(auth()->user()->email), array_map('strtolower', config('campaigns.concierge_emails')), true))
            <a href="{{ route('concierge.index') }}" aria-label="Concierge" title="Concierge" @class(['active' => request()->routeIs('concierge.*')])><span class="nav-icon" aria-hidden="true">◈</span><span class="sidebar-copy nav-text">Concierge</span></a>
        @endif
    </nav>

    <div class="nav-label sidebar-copy"><span>Workspace</span><i></i></div>
    <div class="workspace-usage sidebar-copy">
        <div><span>Pack credits</span><strong>{{ $creditBalance }}</strong></div>
        <div class="usage-meter"><i style="width: {{ min(100, ($creditsUsed / max(1, $monthlyCredits)) * 100) }}%"></i></div>
        <small>{{ $creditsUsed }} used this cycle</small>
    </div>

    <div class="sidebar-footer">
        <div class="signed-in-user"><span>{{ strtoupper(substr(auth()->user()->name, 0, 1)) }}</span><div class="sidebar-copy"><strong>{{ auth()->user()->name }}</strong><small>{{ auth()->user()->email }}</small></div></div>
        <form method="POST" action="{{ route('logout') }}">@csrf<button type="submit" aria-label="Sign out" title="Sign out"><span class="nav-icon" aria-hidden="true">↗</span><span class="sidebar-copy nav-text">Sign out</span></button></form>
    </div>
</aside>
