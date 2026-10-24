<div class="app-shell">
    <a class="skip-link" href="#main-content">Skip to launch packs</a>
    @include('partials.workspace-sidebar')
    <div class="app-main">
        @include('partials.top-bar')
        <main id="main-content" class="lib-page">
            <div class="pagehead">
                <div>
                    <div class="eyebrow">All products</div>
                    <h1 class="display">Launch packs</h1>
                    <p class="muted">A launch pack writes positioning, video scripts and channel copy for one product from its confirmed facts. Each output is reviewed on its own; once all are approved, the product's client hub fills in.</p>
                </div>
                @if($canCreate)<a class="pill pri" href="{{ route('campaign-packs.create') }}" wire:navigate><i class="ti ti-plus" aria-hidden="true"></i> New launch pack</a>@endif
            </div>
            @if($status === 'review')
                <div class="banner rev small" role="status"><span class="grow">Showing launch packs waiting for review.</span><a class="pill ghost sm" href="{{ route('campaign-packs.index') }}" wire:navigate>Show all</a></div>
            @endif
            @if($packs->isEmpty() && $status === 'review')
                <div class="empty"><strong>Nothing is waiting for review</strong><span class="small muted">Launch packs appear here when someone asks for a review.</span></div>
            @elseif($packs->isEmpty())
                <div class="empty">
                    <strong>No launch packs yet</strong>
                    <span class="small muted">Pick a product and we write its launch copy from the facts you confirmed. Nothing is generated until you press start, and you see the credit cost first.</span>
                    @if($canCreate)<a class="pill pri sm" href="{{ route('campaign-packs.create') }}" wire:navigate>Create your first launch pack</a>@endif
                </div>
            @else
                <div class="tw">
                    <table class="tbl">
                        <thead><tr><th scope="col">Product</th><th scope="col">Version</th><th scope="col">Status</th><th scope="col">Updated</th><th scope="col"><span class="sr-only">Open</span></th></tr></thead>
                        <tbody>
                            @foreach($packs as $pack)
                                @php([$tone, $label] = ['approved' => ['ok', 'Approved'], 'review' => ['rev', 'In review'], 'rejected' => ['warn', 'Changes requested'], 'failed' => ['bad', 'Failed'], 'queued' => ['rev', 'Waiting to start'], 'processing' => ['rev', 'Writing'], 'draft' => ['', 'Draft']][$pack->status] ?? ['', str($pack->status)->headline()])
                                <tr wire:key="pack-{{ $pack->id }}">
                                    <td><a class="medium" href="{{ route('campaign-packs.show', $pack) }}" wire:navigate>{{ $pack->product->name }}</a><br><span class="muted xs">{{ $pack->product->brand->name }}</span></td>
                                    <td>{{ $pack->current_version ? 'Version '.$pack->current_version : '—' }}</td>
                                    <td><span class="tag {{ $tone }}"><span class="dot"></span>{{ $label }}</span></td>
                                    <td class="muted">{{ $pack->updated_at->diffForHumans() }}</td>
                                    <td><a class="pill ghost sm" href="{{ route('campaign-packs.show', $pack) }}" wire:navigate>Open</a></td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </main>
    </div>
</div>
