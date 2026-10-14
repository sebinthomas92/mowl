@php
    $defs = \App\Services\ProductKnowledge::FACTS;
    $conflicts = $facts->where('status', 'conflict');
    $statusTag = [
        'ok' => ['ok', 'Confirmed'],
        'conflict' => ['warn', 'Source changed'],
        'missing' => ['', 'Missing'],
        'excluded' => ['bad', 'Excluded'],
    ];
    $checkedAt = $source?->fetched_at ?? $source?->created_at;
@endphp
@if(! $knowledgeReady)
    <div class="banner warn" role="status"><i class="ti ti-database-exclamation" aria-hidden="true"></i><span>Knowledge turns on once the latest database migration has run. Launch packs keep using the product page as before.</span></div>
@else
    @if($conflicts->isNotEmpty())
        <div class="banner warn" role="status"><i class="ti ti-alert-triangle" aria-hidden="true"></i><span><strong>The source changed.</strong> Review each change. Confirmed facts and past approved outputs are never overwritten silently.</span></div>
    @endif

    <div class="tw">
        <table class="tbl knowledge-table">
            <caption class="sr-only">Product facts for {{ $product->name }}</caption>
            <thead><tr><th scope="col">Fact</th><th scope="col">Source says</th><th scope="col">Your confirmed value</th><th scope="col">Status</th><th scope="col"><span class="sr-only">Actions</span></th></tr></thead>
            <tbody>
                @foreach($facts as $item)
                    @php
                        $def = $defs[$item->key];
                        [$tone, $label] = $statusTag[$item->status] ?? ['', ucfirst($item->status)];
                        $inherits = $item->key === 'audience' && $brandAudience && $item->status === 'missing';
                        $overrides = $item->key === 'audience' && $brandAudience && $item->status === 'ok' && $item->confirmed_value !== null
                            && mb_strtolower(trim($item->confirmed_value)) !== mb_strtolower(trim($brandAudience));
                        if ($inherits) {
                            [$tone, $label] = ['', 'From brand'];
                        }
                    @endphp
                    <tr wire:key="fact-{{ $item->key }}" @class(['hl' => $item->status === 'conflict', 'is-excluded' => $item->status === 'excluded'])>
                        <th scope="row" class="fact-name">{{ $def['label'] }}@unless($def['sourced'])<span class="xs muted">Added by your team</span>@endunless</th>
                        <td class="fact-source">
                            @if(! $def['sourced'])<span class="muted">Not on the page</span>
                            @elseif($item->source_value !== null)<span class="clamp">{{ $item->source_value }}</span>
                            @else<span class="muted">Not found</span>@endif
                        </td>
                        <td class="fact-value">
                            @if($editing === $item->key)
                                <form class="fact-form" wire:submit="saveFact">
                                    <label class="sr-only" for="fact-{{ $item->key }}">{{ $def['label'] }}</label>
                                    @if($def['max'] > 200)
                                        <textarea id="fact-{{ $item->key }}" class="input" rows="3" wire:model="factValue" maxlength="{{ $def['max'] }}" placeholder="{{ $def['placeholder'] ?? '' }}" autofocus></textarea>
                                    @else
                                        <input id="fact-{{ $item->key }}" class="input" type="text" wire:model="factValue" maxlength="{{ $def['max'] }}" placeholder="{{ $def['placeholder'] ?? '' }}" autofocus>
                                    @endif
                                    @error('factValue')<span class="field-error" role="alert">{{ $message }}</span>@enderror
                                    <div class="row"><button type="submit" class="pill pri sm">Save</button><button type="button" class="pill ghost sm" wire:click="cancelEdit">Cancel</button></div>
                                </form>
                            @elseif($item->confirmed_value !== null)
                                <span class="clamp">{{ $item->confirmed_value }}</span>
                                @if($item->confirmedBy)<span class="xs muted">Confirmed by {{ $item->confirmedBy->name }} {{ $item->confirmed_at?->diffForHumans() }}</span>@endif
                                @if($overrides)<span class="xs muted">Overrides the {{ $product->brand->name }} default: {{ $brandAudience }}</span>@endif
                            @elseif($inherits)
                                <span class="clamp">{{ $brandAudience }}</span>
                                <span class="xs muted">Inherited from the brand {{ $product->brand->name }}; add a value to override it for this product.</span>
                            @else
                                <span class="muted">Not set</span>
                            @endif
                        </td>
                        <td><span class="tag {{ $tone }}"><span class="dot"></span>{{ $label }}</span></td>
                        <td class="fact-actions">
                            @if($canManage && $editing !== $item->key)
                                <div class="row">
                                    @switch($item->status)
                                        @case('conflict')
                                            <button type="button" class="pill dark sm" wire:click="acceptSource('{{ $item->key }}')">Accept source</button>
                                            <button type="button" class="pill ghost sm" wire:click="keepConfirmed('{{ $item->key }}')">Keep mine</button>
                                            @break
                                        @case('excluded')
                                            <button type="button" class="pill ghost sm" wire:click="restoreFact('{{ $item->key }}')">Restore</button>
                                            @break
                                        @case('missing')
                                            <button type="button" class="pill dark sm" wire:click="editFact('{{ $item->key }}')">Add</button>
                                            @break
                                        @default
                                            <button type="button" class="pill ghost sm" wire:click="editFact('{{ $item->key }}')">Edit</button>
                                    @endswitch
                                    @if(! in_array($item->status, ['excluded', 'missing']) && $item->key !== 'name')
                                        <button type="button" class="iconbtn flat" wire:click="excludeFact('{{ $item->key }}')" aria-label="Exclude {{ strtolower($def['label']) }} from generated content" title="Exclude from generated content"><i class="ti ti-eye-off" aria-hidden="true"></i></button>
                                    @endif
                                </div>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    <div class="grid">
        <section class="card col c6">
            <span class="eyebrow">Sources</span>
            @if($source)
                <div class="li">
                    <span class="ic"><i class="ti ti-world" aria-hidden="true"></i></span>
                    <span class="grow"><a class="small break" href="{{ $source->canonical_url ?: $source->url }}" target="_blank" rel="noopener">{{ $source->canonical_url ?: $source->url }}</a><br><span class="xs muted">Checked {{ $checkedAt?->diffForHumans() ?? 'never' }}</span></span>
                    @if($canManage)
                        <button type="button" class="pill ghost sm" wire:click="refreshSource" wire:loading.attr="disabled" wire:target="refreshSource"><i class="ti ti-refresh" aria-hidden="true" wire:loading.class="spin" wire:target="refreshSource"></i> Refresh</button>
                    @endif
                </div>
            @else
                <p class="small muted">This product has no source page. Facts you add here are still used in every launch pack.</p>
            @endif
        </section>
        <section class="card col c6">
            <span class="eyebrow">How facts are used</span>
            <p class="small">Every launch pack is written from your confirmed values. Excluded facts are left out of generated content entirely. Refreshing the source never changes a confirmed value; it asks you first.</p>
        </section>
    </div>
@endif
