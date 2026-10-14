@php([$tone, $label] = \App\Models\ContentItem::STATUSES[$status] ?? ['', str($status)->headline()])
<span class="tag {{ $tone }}"><span class="dot"></span>{{ $label }}</span>
