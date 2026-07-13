<div class="concierge-shell">
    <a class="skip-link" href="#main-content">Skip to concierge console</a>
    <header class="concierge-header"><a href="{{ route('campaign-packs.index') }}" class="brand-lockup"><img src="/marketing-owl-logo.png" alt="" class="owl-mark"><div><strong>Marketing</strong><strong>Owl</strong></div></a><div><span>INTERNAL ONLY</span><strong>Concierge console</strong></div></header>
    <main class="concierge-page" id="main-content">
        <header class="concierge-title"><div><p class="kicker">SUPPORT OPERATIONS</p><h1>Customer context, without database access.</h1><p>Lookup is restricted to the configured concierge allow-list. Every mutation is written to the workspace audit trail.</p></div></header>
        @if(session('status'))<div class="team-notice" role="status">✓ {{ session('status') }}</div>@endif

        <div class="concierge-layout">
            <aside class="concierge-lookup" aria-label="Customer accounts">
                <label>Account lookup<input wire:model.live.debounce.250ms="query" type="search" placeholder="Workspace or member email"></label>
                <div class="concierge-workspaces">
                    @forelse($workspaces as $option)
                        <button type="button" wire:click="selectWorkspace({{ $option->id }})" @class(['active' => $option->id === $workspaceId])><strong>{{ $option->name }}</strong><small>{{ $option->users()->count() }} members</small></button>
                    @empty
                        <p>No matching account.</p>
                    @endforelse
                </div>
            </aside>

            @if($workspace)
                <section class="concierge-detail" aria-labelledby="account-heading">
                    <header><div><p class="kicker">ACCOUNT DETAIL</p><h2 id="account-heading">{{ $workspace->name }}</h2><p>{{ $members->count() }} members · {{ $creditBalance }} pack credits available</p></div><div class="concierge-alerts"><span @class(['alert' => $failedJobs > 0])>{{ $failedJobs }} failed jobs</span><span @class(['alert' => $costAlerts > 0])>{{ $costAlerts }} cost alerts</span></div></header>

                    <div class="concierge-grid">
                        <section class="concierge-panel"><p class="kicker">CREDITS</p><h3>Adjust balance</h3><form wire:submit="adjustCredits"><label>Credits (+/-)<input wire:model="adjustmentAmount" type="number" min="-50" max="50"></label><label>Required reason<textarea wire:model="adjustmentReason" rows="3" placeholder="Why is this adjustment required?"></textarea></label>@error('adjustmentAmount')<small class="error">{{ $message }}</small>@enderror @error('adjustmentReason')<small class="error">{{ $message }}</small>@enderror<button class="primary-button" type="submit">Record adjustment <span>→</span></button></form></section>
                        <section class="concierge-panel"><p class="kicker">ONBOARDING</p><h3>Beta checklist</h3><div class="onboarding-list">@foreach($onboardingSteps as $step => $label)<button type="button" wire:click="toggleOnboardingStep('{{ $step }}')" @class(['done' => in_array($step, $completedSteps, true)])><span>{{ in_array($step, $completedSteps, true) ? '✓' : '' }}</span>{{ $label }}</button>@endforeach</div></section>
                    </div>

                    <section class="concierge-panel full"><p class="kicker">GENERATIONS</p><h3>Job inspection</h3><div class="concierge-jobs">@forelse($jobs as $job)<article><div><strong>{{ $job->campaignPack?->product?->name ?? 'Campaign pack' }}</strong><small>{{ strtoupper($job->status) }} · {{ $job->phase }} · ${{ number_format((float) $job->estimated_cost, 3) }}</small></div><div>@if($job->status === 'failed')<button type="button" wire:click="retryJob({{ $job->id }})">Retry once</button>@elseif(in_array($job->status, ['queued', 'retrying']))<button type="button" wire:click="cancelJob({{ $job->id }})">Cancel</button>@endif</div></article>@empty<p class="concierge-empty">No generation jobs for this workspace.</p>@endforelse</div></section>

                    <section class="concierge-panel full"><p class="kicker">SUPPORT QUEUES</p><h3>Failed jobs & cost alerts</h3><div class="concierge-jobs">@forelse($attentionJobs as $job)<article><div><strong>{{ $job->campaignPack?->product?->name ?? 'Campaign pack' }}</strong><small>{{ $job->status === 'failed' ? 'FAILED' : 'COST ALERT' }} · ${{ number_format((float) $job->estimated_cost, 3) }} · {{ $job->created_at->diffForHumans() }}</small></div><div>@if($job->status === 'failed')<button type="button" wire:click="retryJob({{ $job->id }})">Retry once</button>@endif</div></article>@empty<p class="concierge-empty">No failed jobs or cost alerts.</p>@endforelse</div></section>

                    <div class="concierge-grid">
                        <section class="concierge-panel"><p class="kicker">SUPPORT NOTES</p><h3>Customer history</h3><form wire:submit="addSupportNote"><textarea wire:model="supportNote" rows="3" placeholder="Add an internal support note"></textarea>@error('supportNote')<small class="error">{{ $message }}</small>@enderror<button class="secondary-button" type="submit">Add note</button></form><div class="concierge-notes">@forelse($notes as $note)<article><p>{{ $note->body }}</p><small>{{ $note->author?->email ?? 'System' }} · {{ $note->created_at->diffForHumans() }}</small></article>@empty<p class="concierge-empty">No notes yet.</p>@endforelse</div></section>
                        <section class="concierge-panel"><p class="kicker">AUDIT TRAIL</p><h3>Recent mutations</h3><div class="concierge-notes">@forelse($auditEvents as $event)<article><strong>{{ str_replace('_', ' ', $event->event) }}</strong><p>{{ $event->reason ?? ($event->metadata['step'] ?? 'Recorded by '.$event->actor?->email) }}</p><small>{{ $event->actor?->email ?? 'System' }} · {{ $event->created_at->diffForHumans() }}</small></article>@empty<p class="concierge-empty">No audit events yet.</p>@endforelse</div></section>
                    </div>
                </section>
            @else
                <section class="concierge-empty-state"><span>◈</span><h2>Select an account</h2><p>Search by workspace name or a member email to inspect support context.</p></section>
            @endif
        </div>
    </main>
</div>
