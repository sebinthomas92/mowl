<aside class="sidebar" :class="mobileNav && 'is-open'" aria-label="Main navigation">
    <button type="button" class="sidebar-close" @click="mobileNav = false" aria-label="Close navigation">×</button>
    <a href="{{ route('campaign-packs.index') }}" class="brand-lockup">
        <img src="/marketing-owl-logo.png" alt="" class="owl-mark">
        <div><strong>Marketing</strong><strong>Owl</strong></div>
    </a>

    <div class="workspace-switcher">
        <span class="workspace-monogram">{{ strtoupper(substr($workspace->name, 0, 2)) }}</span>
        <span><strong>{{ $workspace->name }}</strong><small>{{ ucfirst(auth()->user()->workspaces()->whereKey($workspace->id)->first()->pivot->role) }} workspace</small></span>
        <span class="chevron">⌄</span>
    </div>

    <nav class="primary-nav" aria-label="Workspace sections">
        <a href="{{ route('brands.index') }}" @class(['active' => request()->routeIs('brands.*')])><span>◇</span> Brands</a>
        <a href="{{ route('products.index') }}" @class(['active' => request()->routeIs('products.*')])><span>▱</span> Products</a>
        <a href="{{ route('campaign-packs.index') }}" @class(['active' => request()->routeIs('campaign-packs.*')])><span>▤</span> Campaign packs</a>
    </nav>

    <div class="nav-label"><span>Workspace</span><i></i></div>
    <div class="workspace-usage">
        <div><span>Pack credits</span><strong>50</strong></div>
        <div class="usage-meter"><i style="width: 2%"></i></div>
        <small>1 used this cycle</small>
    </div>

    <div class="sidebar-footer">
        <div class="signed-in-user"><span>{{ strtoupper(substr(auth()->user()->name, 0, 1)) }}</span><div><strong>{{ auth()->user()->name }}</strong><small>{{ auth()->user()->email }}</small></div></div>
        <form method="POST" action="{{ route('logout') }}">@csrf<button type="submit"><span>↗</span> Sign out</button></form>
    </div>
</aside>
