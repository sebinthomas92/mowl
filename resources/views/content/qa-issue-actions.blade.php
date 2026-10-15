{{-- Where a QA issue sits in the pack and how to fix it. Expects $issue from CampaignWorkspace. --}}
@if(! empty($issue['where']))<small class="qa-where">In {{ $issue['where'] }}</small>@endif
@if($issue['type'] === 'source')
    <small>Approve the product source in the <a href="{{ route('review.index') }}" wire:navigate>Review queue</a>.</small>
@elseif(! empty($issue['fixed']) || ! empty($issue['edit_url']))
    <span class="qa-fix">
        @if(! empty($issue['fixed']))
            <button type="button" class="pill pri sm" wire:click="removeUnsupportedWording(@js($issue['path']), @js($issue['term']))" wire:loading.attr="disabled" title="Saves a new draft version with: {{ $issue['fixed'] }}">Remove “{{ $issue['term'] }}”</button>
        @endif
        @if(! empty($issue['edit_url']))<a href="{{ $issue['edit_url'] }}" wire:navigate>Edit this line</a>@endif
    </span>
    @if(! empty($issue['fixed']))<small>Becomes: “{{ $issue['fixed'] }}”</small>@endif
@elseif($issue['type'] === 'claim')
    <small>Regenerate the section that uses this wording.</small>
@else
    <small>Regenerate the section with this problem.</small>
@endif
