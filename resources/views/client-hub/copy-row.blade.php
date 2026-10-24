@php($length = mb_strlen($text))
<div class="copy-row">
    <p class="copy-text">{{ $text }}</p>
    <span class="copy-meta">
        @if($limit && $length > $limit)
            <span class="tag {{ $hard ? 'bad' : 'warn' }}">{{ $length }}/{{ $limit }}</span>
        @elseif($limit)
            <span class="muted xs num">{{ $length }}/{{ $limit }}</span>
        @endif
        <button type="button" class="pill ghost sm copy-btn" @click="copy(@js($text), @js($key))" aria-label="Copy: {{ \Illuminate\Support\Str::limit($text, 60) }}">
            <i class="ti" :class="copied === @js($key) ? 'ti-check' : 'ti-copy'" aria-hidden="true"></i><span x-text="copied === @js($key) ? 'Copied' : 'Copy'">Copy</span>
        </button>
    </span>
</div>
