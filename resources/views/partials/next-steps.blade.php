{{--
    A short numbered path with one clear next action. Expects $steps: a list of
    ['title' => ..., 'hint' => ..., 'done' => bool, 'skip' => bool, 'label' => button text, 'url' => link, 'action' => Livewire method],
    plus optional $eyebrow and $heading. The first step that is not done is the current one.
--}}
@php
    // A step that cannot be done here (skip) is passed over, so it never blocks the steps after it.
    $currentIndex = collect($steps)->search(fn ($step) => ! $step['done'] && empty($step['skip']));
@endphp
<section class="card next-steps" aria-labelledby="next-steps-{{ $id ?? 'title' }}">
    <div class="row">
        <div class="grow">
            <span class="eyebrow">{{ $eyebrow ?? 'Next step' }}</span>
            <h2 id="next-steps-{{ $id ?? 'title' }}" class="next-steps-title">{{ $heading }}</h2>
        </div>
        <span class="muted small">{{ collect($steps)->where('done', true)->count() }} of {{ count($steps) }} done</span>
    </div>
    <ol class="next-steps-list">
        @foreach($steps as $index => $step)
            @php($current = $index === $currentIndex)
            <li @class(['done' => $step['done'], 'current' => $current, 'skipped' => ! $step['done'] && ! empty($step['skip'])])>
                <span class="next-steps-mark" aria-hidden="true">@if($step['done'])<i class="ti ti-check"></i>@elseif(! empty($step['skip']))<i class="ti ti-minus"></i>@else{{ $index + 1 }}@endif</span>
                <span class="grow">
                    <strong class="medium">{{ $step['title'] }}</strong>@if($step['done'])<span class="sr-only"> (done)</span>@endif
                    @if(! empty($step['hint']))<br><span class="muted xs">{{ $step['hint'] }}</span>@endif
                </span>
                @if($current && ! empty($step['label']))
                    @if(! empty($step['action']))
                        <button type="button" class="pill pri sm" wire:click="{{ $step['action'] }}" wire:loading.attr="disabled">{{ $step['label'] }} <i class="ti ti-arrow-right" aria-hidden="true"></i></button>
                    @elseif(! empty($step['url']))
                        <a class="pill pri sm" href="{{ $step['url'] }}" @if(! str_contains($step['url'], '#')) wire:navigate @endif>{{ $step['label'] }} <i class="ti ti-arrow-right" aria-hidden="true"></i></a>
                    @endif
                @elseif($current && ! empty($step['note']))
                    <span class="muted xs">{{ $step['note'] }}</span>
                @endif
            </li>
        @endforeach
    </ol>
</section>
