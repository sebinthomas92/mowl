{{-- Generic rendering for nested copy such as channel handoffs. --}}
@foreach($data as $key => $value)
    @continue(in_array((string) $key, ['id', 'claim_ids', 'supporting_claim_ids'], true))
    @if(is_array($value) && $value !== [] && ! array_is_list($value))
        <details class="preview-details" @if($depth === 0 && $loop->first) open @endif>
            <summary>{{ str((string) $key)->replace('_', ' ')->title() }}</summary>
            <div class="col">@include('content.tree', ['data' => $value, 'depth' => $depth + 1])</div>
        </details>
    @elseif(is_array($value))
        @continue($value === [])
        <div class="small"><span class="muted">{{ str((string) $key)->replace('_', ' ')->ucfirst() }}</span>
            <ul class="preview-list">@foreach($value as $entry)<li>{{ is_array($entry) ? collect($entry)->filter(fn ($part) => is_scalar($part))->implode(' · ') : $entry }}</li>@endforeach</ul>
        </div>
    @elseif(filled($value))
        <div class="kv"><span>{{ str((string) $key)->replace('_', ' ')->ucfirst() }}</span><span class="break">{{ $value }}</span></div>
    @endif
@endforeach
