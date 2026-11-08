@if($archiveAvailable && $canCreate)
    @if($product->isArchived())
        <button type="button" class="pill ghost sm" wire:click="restore({{ $product->id }})">Restore</button>
    @else
        <button type="button" class="iconbtn flat" wire:click="archive({{ $product->id }})" wire:confirm="Archive {{ $product->name }}? It leaves this list but keeps its facts, launch packs and approvals. You can restore it from Archived." aria-label="Archive {{ $product->name }}" title="Archive"><i class="ti ti-archive" aria-hidden="true"></i></button>
    @endif
@endif
