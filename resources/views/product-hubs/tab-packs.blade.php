<section class="card">
    <div class="row"><span class="eyebrow">Launch packs for {{ $product->name }}</span><span class="sp"></span><a class="pill pri sm" href="{{ route('campaign-packs.create', ['product' => $product->id]) }}" wire:navigate><i class="ti ti-plus" aria-hidden="true"></i>New launch pack</a></div>
    @forelse($packs as $pack)
        @include('product-hubs.pack-row', ['pack' => $pack])
    @empty
        <div class="empty"><strong>No launch packs yet</strong><span class="small muted">Each pack is versioned. Only approved versions reach the client hub.</span></div>
    @endforelse
</section>
