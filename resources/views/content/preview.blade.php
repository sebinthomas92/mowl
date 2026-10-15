{{-- Renders an item body by the section it came from. Variations render like their original. --}}
@php
    $section = $item->section ?? $item->variationOf?->section ?? '';
    $kind = $item->banner_creative_id ? 'banner' : (str_starts_with($section, 'creative_routes') ? 'route' : $section);
    $time = fn ($value) => sprintf('%g', (float) $value);
@endphp
@switch($kind)
    @case('banner')
        @include('content.thumb', ['item' => $item, 'height' => 300])
        <div class="kv"><span>Headline</span><span>{{ $body['headline'] ?? '' }}</span></div>
        @if(filled($body['supporting_text'] ?? null))<div class="kv"><span>Supporting text</span><span>{{ $body['supporting_text'] }}</span></div>@endif
        @if(filled($body['cta'] ?? null))<div class="kv"><span>Call to action</span><span>{{ $body['cta'] }}</span></div>@endif
        <div class="kv"><span>Direction · size</span><span>{{ $body['direction'] ?? '' }} · {{ $body['size'] ?? '' }}</span></div>
        @break
    @case('positioning')
        <p class="display preview-lede">{{ $body['value_proposition'] ?? '' }}</p>
        @if(filled($body['brand_position'] ?? null))<p class="small">{{ $body['brand_position'] }}</p>@endif
        @foreach($body['audience_priorities'] ?? [] as $audience)
            <div class="li small"><span class="grow"><strong class="medium">{{ $audience['name'] ?? '' }}</strong><br><span class="muted">{{ $audience['need'] ?? '' }}@if(filled($audience['buyer_moment'] ?? null)) · {{ $audience['buyer_moment'] }}@endif</span></span></div>
        @endforeach
        @break
    @case('ranked_angles')
        @foreach($body['angles'] ?? [] as $angle)
            <div class="li small">
                <span class="tag num">{{ str_pad((string) ($angle['rank'] ?? $loop->iteration), 2, '0', STR_PAD_LEFT) }}</span>
                <span class="grow"><strong class="medium">{{ $angle['title'] ?? '' }}</strong><br><span class="muted">{{ $angle['core_idea'] ?? '' }}</span></span>
                @if(($angle['status'] ?? '') === 'recommended')<span class="tag ok"><span class="dot"></span>Recommended</span>@endif
            </div>
        @endforeach
        @break
    @case('route')
        <span class="thumb thumb-video preview-video" aria-hidden="true"><span class="display">{{ $body['core_promise'] ?? $body['name'] ?? '' }}</span></span>
        <div>
            <div class="kv"><span>Target buyer</span><span>{{ $body['target_buyer'] ?? '' }}</span></div>
            <div class="kv"><span>Buyer moment</span><span>{{ $body['buyer_moment'] ?? '' }}</span></div>
            <div class="kv"><span>Tone · length</span><span>{{ $body['tone'] ?? '' }} · {{ $time($body['total_duration_seconds'] ?? 0) }} s</span></div>
        </div>
        @if($body['hooks'] ?? [])
            <span class="eyebrow">Hooks</span>
            <ol class="preview-list">@foreach($body['hooks'] as $hook)<li>{{ $hook }}</li>@endforeach</ol>
        @endif
        @if($body['voiceover'] ?? [])
            <span class="eyebrow">Timed voiceover</span>
            @foreach($body['voiceover'] as $line)
                <div class="timed"><time class="num">{{ $time($line['start_seconds'] ?? 0) }}–{{ $time($line['end_seconds'] ?? 0) }}s</time><span>{{ $line['line'] ?? '' }}<br><span class="xs muted">{{ \App\Support\VoiceoverTiming::words($line['line'] ?? '') }} words · {{ \App\Support\VoiceoverTiming::pace($line) ?? 0 }} wpm</span></span></div>
            @endforeach
        @endif
        @if($body['captions'] ?? [])
            <span class="eyebrow">Captions</span>
            @foreach($body['captions'] as $caption)
                <div class="timed"><time class="num">{{ $time($caption['start_seconds'] ?? 0) }}–{{ $time($caption['end_seconds'] ?? 0) }}s</time><span>{{ $caption['text'] ?? '' }}</span></div>
            @endforeach
        @endif
        @if($body['shot_plan'] ?? [])
            <span class="eyebrow">Shot plan · {{ count($body['shot_plan']) }} shots</span>
            @foreach($body['shot_plan'] as $shot)
                <div class="timed"><time class="num">{{ $time($shot['start_seconds'] ?? 0) }}–{{ $time($shot['end_seconds'] ?? 0) }}s</time><span><strong class="medium">{{ ucfirst($shot['purpose'] ?? '') }}</strong> · {{ $shot['scene'] ?? '' }}<br><span class="xs muted">{{ $shot['camera_framing'] ?? '' }}</span></span></div>
            @endforeach
        @endif
        @foreach($body['platform_assets'] ?? [] as $platform => $asset)
            <details class="preview-details">
                <summary>{{ str($platform)->replace('_', ' ')->title() }}</summary>
                <p class="small">{{ $asset['primary_copy'] ?? '' }}</p>
                @if(filled($asset['title'] ?? null))<p class="small"><strong class="medium">{{ $asset['title'] }}</strong></p>@endif
                @if(filled($asset['cta'] ?? null))<p class="xs muted">CTA: {{ $asset['cta'] }}</p>@endif
            </details>
        @endforeach
        @break
    @case('offers')
        @forelse($body['offers'] ?? [] as $offer)
            <div class="li small"><span class="grow"><strong class="medium">{{ $offer['wording'] ?? '' }}</strong><br><span class="muted">{{ $offer['audience_or_situation'] ?? '' }}</span>@if(filled($offer['limitations'] ?? null))<br><span class="xs muted">Limits: {{ $offer['limitations'] }}</span>@endif</span></div>
        @empty
            <p class="small muted">No safe offer framing was generated.</p>
        @endforelse
        @break
    @default
        @include('content.tree', ['data' => $body, 'depth' => 0])
@endswitch
