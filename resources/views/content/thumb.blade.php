@php($imageUrl = $imageUrl ?? ($item->banner_creative_id && $item->campaign_pack_id ? route('campaign-banners.image', [$item->campaign_pack_id, $item->banner_creative_id]) : null))
{{-- With a $ratio (e.g. "4 / 5" for banners) the thumb keeps the creative's own shape, so nothing is cropped. --}}
@php($box = ! empty($ratio) ? "aspect-ratio: {$ratio}" : "height: {$height}px")
@if($item->type === 'image' && $imageUrl)
    <span @class(['thumb', 'thumb-whole' => ! empty($ratio)]) style="{{ $box }}"><img src="{{ $imageUrl }}" alt="" loading="lazy"></span>
@elseif(! empty($snippet) && ($line = collect(\Illuminate\Support\Arr::dot($item->latestVersion?->body ?? []))->first(fn ($value) => is_string($value) && mb_strlen($value) > 24)))
    <span @class(['thumb', 'thumb-text', 'thumb-video' => $item->type === 'video']) style="height: {{ $height }}px" aria-hidden="true"><span>{{ \Illuminate\Support\Str::limit($line, 110) }}</span></span>
@elseif($item->type === 'video')
    <span class="thumb thumb-video" style="height: {{ $height }}px" aria-hidden="true"><i class="ti ti-player-play"></i></span>
@else
    <span class="thumb" style="height: {{ $height }}px" aria-hidden="true">Aa</span>
@endif
