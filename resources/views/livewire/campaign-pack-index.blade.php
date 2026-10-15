<div class="app-shell">
    <a class="skip-link" href="#main-content">Skip to launch packs</a>
    @include('partials.workspace-sidebar')
    <div class="app-main">
        @include('partials.top-bar')
        <main id="main-content">
        <section class="library-page packs-library">
            <header class="library-header"><div><p class="kicker">PRODUCT LAUNCHES</p><h1>Launch packs</h1><p>Approved, copy-ready campaign intelligence for your media buying team.</p></div>@if($canCreate)<a class="primary-link" href="{{ route('campaign-packs.create') }}">＋ New launch pack</a>@endif</header>
            <div class="stat-strip"><div><small>PACKS CREATED</small><strong>{{ $packs->count() }}</strong></div>@if($canSeeCosts)<div><small>CREDITS REMAINING</small><strong>{{ $workspace->spendableCredits() }}</strong></div><div><small>COGS STATUS</small><strong @class(['healthy' => $packs->every(fn($pack) => $pack->estimated_cost < config('campaigns.cogs_alert'))])>{{ $packs->contains(fn($pack) => $pack->estimated_cost >= config('campaigns.cogs_alert')) ? 'Needs attention' : 'On target' }}</strong></div>@endif</div>
            @if($status === 'review')
                <p class="queue-filter" role="status"><span>Showing packs waiting for review</span><a class="pill ghost sm" href="{{ route('campaign-packs.index') }}" wire:navigate>Show all packs</a></p>
            @endif
            @if($packs->isEmpty() && $status === 'review')
                <div class="library-empty"><span>✓</span><h2>The review queue is clear</h2><p>Packs appear here when someone asks for a review of a version.</p></div>
            @elseif($packs->isEmpty())
                <div class="library-empty"><span>▤</span><h2>Your launch pack library is empty</h2><p>Turn an approved product page into your first structured, source-linked pack.</p>@if($canCreate)<a href="{{ route('campaign-packs.create') }}">Build first pack →</a>@endif</div>
            @else
                <div class="pack-list">@foreach($packs as $pack)<a href="{{ route('campaign-packs.show', $pack) }}" class="pack-row"><div class="pack-row-mark">{{ strtoupper(substr($pack->product->name, 0, 2)) }}</div><div class="pack-row-main"><small>{{ $pack->product->brand->name }}</small><h2>{{ $pack->product->name }}</h2><p>{{ $pack->current_version ? 'Launch pack v'.$pack->current_version : 'Generation job #'.$pack->latestGenerationJob?->id }} · Updated {{ $pack->updated_at->diffForHumans() }}</p></div><span @class(['approved-badge', 'job-status-failed' => $pack->status === 'failed', 'job-status-working' => in_array($pack->status, ['queued','processing'])])>{{ $pack->status === 'approved' ? '✓' : '●' }} {{ ucfirst($pack->status) }}</span><strong>Open {{ $pack->status === 'approved' ? 'pack' : 'job' }} →</strong></a>@endforeach</div>
            @endif
        </section>
        </main>
    </div>
</div>
