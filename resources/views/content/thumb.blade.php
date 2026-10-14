@php($imageUrl = $imageUrl ?? ($item->banner_creative_id && $item->campaign_pack_id ? route('campaign-banners.image', [$item->campaign_pack_id, $item->banner_creative_id]) : null))
@if($item->type === 'image' && $imageUrl)
    <span class="thumb" style="height: {{ $height }}px"><img src="{{ $imageUrl }}" alt="" loading="lazy"></span>
@elseif($item->type === 'video')
    <span class="thumb thumb-video" style="height: {{ $height }}px" aria-hidden="true"><i class="ti ti-player-play"></i></span>
@else
    <span class="thumb" style="height: {{ $height }}px" aria-hidden="true">Aa</span>
@endif
