<div class="search-wrap" x-data="{ open: false }" @click.outside="open = false" @keydown.escape="open = false; $refs.input.blur()">
    <form class="search" role="search" @submit.prevent="$el.querySelector('.search-results a')?.click()">
        <i class="ti ti-search" aria-hidden="true"></i>
        <input x-ref="input" wire:model.live.debounce.250ms="query" @focus="open = true" @input="open = true" type="search" placeholder="Search products and content" aria-label="Search products and content" autocomplete="off">
    </form>
    @if(mb_strlen($term) >= 2)
        <div class="menu search-results" x-show="open" x-cloak role="listbox" aria-label="Search results">
            @forelse($results as $group => $items)
                <div class="mh">{{ $group }}</div>
                @foreach($items as $item)
                    <a href="{{ $item['url'] }}" wire:navigate role="option"><i class="ti ti-{{ $item['icon'] }}" aria-hidden="true"></i><span class="grow">{{ $item['label'] }}<br><span class="xs muted">{{ $item['meta'] }}</span></span></a>
                @endforeach
            @empty
                <p class="search-empty">Nothing matches “{{ $term }}”.</p>
            @endforelse
        </div>
    @endif
</div>
