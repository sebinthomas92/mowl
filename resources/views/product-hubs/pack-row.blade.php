@php($tone = ['approved' => 'ok', 'review' => 'rev', 'failed' => 'bad', 'rejected' => 'warn', 'processing' => 'rev', 'queued' => 'rev'][$pack->status] ?? '')
<div class="li">
    <span class="ic {{ $tone ?: 'neu' }}"><i class="ti ti-stack-2" aria-hidden="true"></i></span>
    <span class="grow"><strong class="medium">{{ $pack->name ?: $product->name.' launch pack' }}</strong><br><span class="small muted">{{ $pack->current_version ? 'v'.$pack->current_version.' · ' : '' }}Updated {{ $pack->updated_at->diffForHumans() }}</span></span>
    <span class="tag {{ $tone }}"><span class="dot"></span>{{ ['review' => 'In review', 'rejected' => 'Changes requested'][$pack->status] ?? str($pack->status)->headline() }}</span>
    <a class="pill ghost sm" href="{{ route('campaign-packs.show', $pack) }}" wire:navigate>Open</a>
</div>
