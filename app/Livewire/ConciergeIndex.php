<?php

namespace App\Livewire;

use App\Models\CampaignGenerationJob;
use App\Models\Workspace;
use App\Models\WorkspaceAuditEvent;
use App\Models\WorkspaceCredit;
use App\Models\WorkspaceOnboardingState;
use App\Models\WorkspaceSupportNote;
use App\Services\CampaignJobDispatcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Component;

class ConciergeIndex extends Component
{
    public string $query = '';

    public ?int $workspaceId = null;

    public int $adjustmentAmount = 0;

    public string $adjustmentReason = '';

    public string $adjustmentKey = '';

    public string $supportNote = '';

    public function mount(): void
    {
        $this->authorizeConcierge();
        $this->adjustmentKey = (string) Str::uuid();
    }

    public function selectWorkspace(int $workspaceId): void
    {
        $this->authorizeConcierge();
        Workspace::query()->findOrFail($workspaceId);
        $this->workspaceId = $workspaceId;
    }

    public function adjustCredits(): void
    {
        $this->authorizeConcierge();
        $data = $this->validate([
            'workspaceId' => ['required', 'integer', 'exists:workspaces,id'],
            'adjustmentAmount' => ['required', 'integer', 'between:-50,50', 'not_in:0'],
            'adjustmentReason' => ['required', 'string', 'min:5', 'max:500'],
            'adjustmentKey' => ['required', 'uuid'],
        ]);

        $created = DB::transaction(function () use ($data): bool {
            if (WorkspaceCredit::query()->where('idempotency_key', $data['adjustmentKey'])->exists()) {
                return false;
            }

            $workspace = Workspace::query()->lockForUpdate()->findOrFail($data['workspaceId']);
            $credit = WorkspaceCredit::create([
                'workspace_id' => $workspace->id,
                'amount' => $data['adjustmentAmount'],
                'event' => 'concierge_adjustment',
                'description' => 'Concierge credit adjustment',
                'idempotency_key' => $data['adjustmentKey'],
                'metadata' => ['reason' => $data['adjustmentReason']],
            ]);
            WorkspaceAuditEvent::create([
                'workspace_id' => $workspace->id,
                'actor_user_id' => auth()->id(),
                'event' => 'credits_adjusted',
                'subject_type' => WorkspaceCredit::class,
                'subject_id' => $credit->id,
                'reason' => $data['adjustmentReason'],
                'metadata' => ['amount' => $data['adjustmentAmount'], 'idempotency_key' => $data['adjustmentKey']],
            ]);

            return true;
        });

        if ($created) {
            $this->adjustmentAmount = 0;
            $this->adjustmentReason = '';
            $this->adjustmentKey = (string) Str::uuid();
            session()->flash('status', 'Credit adjustment recorded.');
        }
    }

    public function addSupportNote(): void
    {
        $this->authorizeConcierge();
        $data = $this->validate([
            'workspaceId' => ['required', 'integer', 'exists:workspaces,id'],
            'supportNote' => ['required', 'string', 'min:3', 'max:2000'],
        ]);

        $note = WorkspaceSupportNote::create([
            'workspace_id' => $data['workspaceId'],
            'author_user_id' => auth()->id(),
            'body' => $data['supportNote'],
        ]);
        WorkspaceAuditEvent::create([
            'workspace_id' => $data['workspaceId'],
            'actor_user_id' => auth()->id(),
            'event' => 'support_note_added',
            'subject_type' => WorkspaceSupportNote::class,
            'subject_id' => $note->id,
        ]);
        $this->supportNote = '';
        session()->flash('status', 'Support note added.');
    }

    public function retryJob(int $jobId, CampaignJobDispatcher $dispatcher): void
    {
        $this->authorizeConcierge();
        $workspace = $this->selectedWorkspace();

        $job = DB::transaction(function () use ($workspace, $jobId): CampaignGenerationJob {
            $job = $workspace->generationJobs()->whereKey($jobId)->lockForUpdate()->firstOrFail();
            abort_unless($job->status === 'failed', 422);
            $job->update(['status' => 'queued', 'phase' => 'support_retry', 'error_code' => null, 'error_message' => null]);
            WorkspaceAuditEvent::create([
                'workspace_id' => $workspace->id,
                'actor_user_id' => auth()->id(),
                'event' => 'job_retry_requested',
                'subject_type' => CampaignGenerationJob::class,
                'subject_id' => $job->id,
                'reason' => 'Concierge retry',
            ]);

            return $job;
        });

        $dispatcher->dispatch($job->id);
        session()->flash('status', 'Job queued for one safe retry.');
    }

    public function cancelJob(int $jobId): void
    {
        $this->authorizeConcierge();
        $workspace = $this->selectedWorkspace();

        DB::transaction(function () use ($workspace, $jobId): void {
            $job = $workspace->generationJobs()->whereKey($jobId)->lockForUpdate()->firstOrFail();
            abort_unless(in_array($job->status, ['queued', 'retrying']), 422);
            $job->update(['status' => 'cancelled', 'phase' => 'support_cancelled']);
            WorkspaceAuditEvent::create([
                'workspace_id' => $workspace->id,
                'actor_user_id' => auth()->id(),
                'event' => 'job_cancelled',
                'subject_type' => CampaignGenerationJob::class,
                'subject_id' => $job->id,
                'reason' => 'Concierge cancellation',
            ]);
        });

        session()->flash('status', 'Queued job cancelled.');
    }

    public function toggleOnboardingStep(string $step): void
    {
        $this->authorizeConcierge();
        abort_unless(array_key_exists($step, $this->onboardingSteps()), 422);
        $workspace = $this->selectedWorkspace();
        $state = WorkspaceOnboardingState::firstOrCreate(['workspace_id' => $workspace->id], ['completed_steps' => []]);
        $completed = $state->completed_steps ?? [];

        if (in_array($step, $completed, true)) {
            $completed = array_values(array_diff($completed, [$step]));
        } else {
            $completed[] = $step;
        }

        $state->update(['completed_steps' => $completed]);
        WorkspaceAuditEvent::create([
            'workspace_id' => $workspace->id,
            'actor_user_id' => auth()->id(),
            'event' => 'onboarding_step_toggled',
            'subject_type' => WorkspaceOnboardingState::class,
            'subject_id' => $state->id,
            'metadata' => ['step' => $step, 'completed' => in_array($step, $completed, true)],
        ]);
    }

    private function selectedWorkspace(): Workspace
    {
        abort_unless($this->workspaceId, 422);

        return Workspace::query()->findOrFail($this->workspaceId);
    }

    private function authorizeConcierge(): void
    {
        $emails = array_map('strtolower', config('campaigns.concierge_emails'));
        abort_unless(in_array(strtolower((string) auth()->user()?->email), $emails, true), 404);
    }

    private function onboardingSteps(): array
    {
        return [
            'discovery_complete' => 'Confirm agency goals and campaign cadence',
            'team_invited' => 'Invite the working media-buying team',
            'first_product_ready' => 'Validate the first product source and truth',
            'first_pack_reviewed' => 'Review the first campaign pack together',
        ];
    }

    public function render()
    {
        $this->authorizeConcierge();
        $workspaces = Workspace::query()
            ->when($this->query !== '', function ($query): void {
                $term = '%'.$this->query.'%';
                $query->where(function ($lookup) use ($term): void {
                    $lookup->where('name', 'like', $term)
                        ->orWhereHas('users', fn ($users) => $users->where('email', 'like', $term));
                });
            })
            ->latest()
            ->limit(20)
            ->get();
        $workspace = $this->workspaceId ? Workspace::query()->with('onboardingState')->findOrFail($this->workspaceId) : null;

        return view('livewire.concierge-index', [
            'workspaces' => $workspaces,
            'workspace' => $workspace,
            'members' => $workspace?->users()->orderByPivot('created_at')->get() ?? collect(),
            'jobs' => $workspace?->generationJobs()->with('campaignPack.product')->latest()->limit(12)->get() ?? collect(),
            'attentionJobs' => $workspace?->generationJobs()
                ->with('campaignPack.product')
                ->where(fn ($query) => $query->where('status', 'failed')->orWhere('cost_alert', true))
                ->latest()
                ->limit(12)
                ->get() ?? collect(),
            'notes' => $workspace?->supportNotes()->with('author')->latest()->limit(10)->get() ?? collect(),
            'auditEvents' => $workspace?->auditEvents()->with('actor')->latest()->limit(12)->get() ?? collect(),
            'creditBalance' => $workspace?->creditBalance() ?? 0,
            'failedJobs' => $workspace?->generationJobs()->where('status', 'failed')->count() ?? 0,
            'costAlerts' => $workspace?->generationJobs()->where('cost_alert', true)->count() ?? 0,
            'onboardingSteps' => $this->onboardingSteps(),
            'completedSteps' => $workspace?->onboardingState?->completed_steps ?? [],
        ])->layout('components.layouts.app');
    }
}
