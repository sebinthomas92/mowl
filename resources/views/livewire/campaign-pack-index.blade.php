<div class="app-shell" x-data="{ mobileNav: false }">
    <a class="skip-link" href="#main-content">Skip to campaign packs</a>
    @include('partials.workspace-sidebar')
    <main class="main-panel" id="main-content">
        <header class="mobile-header"><button type="button" @click="mobileNav = !mobileNav" :aria-expanded="mobileNav.toString()" aria-label="Toggle navigation">☰</button><div><img src="/marketing-owl-logo.png" alt=""> Marketing Owl</div><span>Beta</span></header>
        <section class="library-page packs-library">
            <header class="library-header"><div><p class="kicker">CAMPAIGN OPERATIONS</p><h1>Campaign packs</h1><p>Approved, copy-ready campaign intelligence for your media buying team.</p></div><a class="primary-link" href="{{ route('campaign-packs.create') }}">＋ New campaign pack</a></header>
            <div class="stat-strip"><div><small>PACKS CREATED</small><strong>{{ $packs->count() }}</strong></div><div><small>CREDITS REMAINING</small><strong>{{ max(0, 50 - $packs->count()) }}</strong></div><div><small>COGS STATUS</small><strong class="healthy">On target</strong></div></div>
            @if($packs->isEmpty())
                <div class="library-empty"><span>▤</span><h2>Your campaign library is empty</h2><p>Turn an approved product page into your first structured, source-linked pack.</p><a href="{{ route('campaign-packs.create') }}">Build first pack →</a></div>
            @else
                <div class="pack-list">@foreach($packs as $pack)<a href="{{ route('campaign-packs.show', $pack) }}" class="pack-row"><div class="pack-row-mark">{{ strtoupper(substr($pack->product->name, 0, 2)) }}</div><div class="pack-row-main"><small>{{ $pack->product->brand->name }}</small><h2>{{ $pack->product->name }}</h2><p>Campaign Pack v{{ $pack->current_version }} · Updated {{ $pack->updated_at->diffForHumans() }}</p></div><span class="approved-badge">✓ {{ ucfirst($pack->status) }}</span><strong>Open pack →</strong></a>@endforeach</div>
            @endif
        </section>
    </main>
</div>
