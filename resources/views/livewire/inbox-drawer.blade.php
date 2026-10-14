<div class="inbox" x-data="{ open: false }" @keydown.escape.window="open = false">
    <button type="button" class="iconbtn" @click="open = true" aria-label="Inbox, {{ $unread }} unread" :aria-expanded="open.toString()">
        <i class="ti ti-bell" aria-hidden="true"></i>
        @if($unread)<span class="badge num">{{ $unread > 99 ? '99+' : $unread }}</span>@endif
    </button>
    <template x-teleport="body">
        <div x-show="open" x-cloak>
            <div class="scrim" @click="open = false"></div>
            <aside class="drawer" role="dialog" aria-modal="true" aria-label="Inbox" x-trap.noscroll="open">
                <div class="drawer-head">
                    <h2 class="display">Inbox</h2>
                    @if($unread)<button type="button" class="linkbtn" wire:click="markAllRead">Mark all read</button>@endif
                    <button type="button" class="iconbtn" @click="open = false" aria-label="Close inbox"><i class="ti ti-x" aria-hidden="true"></i></button>
                </div>
                @forelse($items as $item)
                    @php($icon = \App\Notifications\CampaignPackActivity::EVENTS[$item->data['event'] ?? '']['icon'] ?? 'bell')
                    <button type="button" wire:key="note-{{ $item->id }}" wire:click="open('{{ $item->id }}')" @class(['note-item', 'unread' => ! $item->read_at])>
                        <span class="note-ic" aria-hidden="true"><i class="ti ti-{{ $icon }}"></i></span>
                        <span class="grow"><span class="note-text">{{ $item->data['title'] ?? 'Workspace activity' }}</span><span class="xs muted">{{ $item->created_at->diffForHumans() }}</span></span>
                        @unless($item->read_at)<span class="sr-only">Unread</span>@endunless
                    </button>
                @empty
                    <div class="empty"><strong>No activity yet</strong><span class="small muted">Review requests, approvals and comments on launch packs show up here.</span></div>
                @endforelse
            </aside>
        </div>
    </template>
</div>
