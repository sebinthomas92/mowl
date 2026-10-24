<?php

namespace App\Livewire;

use App\Concerns\InteractsWithWorkspace;
use App\Models\Workspace;
use App\Models\WorkspaceAuditEvent;
use App\Support\Membership;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Livewire\Component;

class WorkspaceSettings extends Component
{
    use InteractsWithWorkspace;

    public string $name = '';

    public string $kind = 'agency';

    public function mount(): void
    {
        $workspace = $this->currentWorkspace();
        $this->name = $workspace->name;
        $this->kind = $workspace->isAgency() ? 'agency' : 'store';
    }

    public function save(): void
    {
        $workspace = $this->currentWorkspace();
        $this->authorizeOwner($workspace);
        $kindAvailable = Schema::hasColumn('workspaces', 'kind');
        $data = $this->validate([
            'name' => ['required', 'string', 'max:120'],
            'kind' => ['required', 'in:'.implode(',', array_keys(Workspace::KINDS))],
        ]);

        DB::transaction(function () use ($workspace, $data, $kindAvailable): void {
            $lockedWorkspace = Workspace::query()->lockForUpdate()->findOrFail($workspace->id);
            $previousName = $lockedWorkspace->name;
            $previousKind = $lockedWorkspace->isAgency() ? 'agency' : 'store';
            $lockedWorkspace->update(array_merge(['name' => $data['name']], $kindAvailable ? ['kind' => $data['kind']] : []));

            if ($kindAvailable && $previousKind !== $data['kind']) {
                WorkspaceAuditEvent::create([
                    'workspace_id' => $lockedWorkspace->id,
                    'actor_user_id' => auth()->id(),
                    'event' => 'workspace_kind_changed',
                    'subject_type' => Workspace::class,
                    'subject_id' => $lockedWorkspace->id,
                    'metadata' => ['from' => $previousKind, 'to' => $data['kind']],
                ]);
            }

            if ($previousName !== $lockedWorkspace->name) {
                WorkspaceAuditEvent::create([
                    'workspace_id' => $lockedWorkspace->id,
                    'actor_user_id' => auth()->id(),
                    'event' => 'workspace_renamed',
                    'subject_type' => Workspace::class,
                    'subject_id' => $lockedWorkspace->id,
                    'metadata' => ['from' => $previousName, 'to' => $lockedWorkspace->name],
                ]);
            }
        });

        session()->flash('status', 'Workspace settings saved.');
    }

    private function authorizeOwner(Workspace $workspace): void
    {
        abort_unless(
            auth()->user()->workspaces()->whereKey($workspace->id)->wherePivot('role', 'owner')->exists(),
            403,
        );
    }

    public function render()
    {
        $workspace = $this->currentWorkspace();
        $membership = auth()->user()->workspaces()->whereKey($workspace->id)->firstOrFail();

        return view('livewire.workspace-settings', [
            'workspace' => $workspace,
            'kindAvailable' => Schema::hasColumn('workspaces', 'kind'),
            'isOwner' => $membership->pivot->role === 'owner',
            'owner' => $workspace->users()->wherePivot('role', 'owner')->first(),
            'members' => $workspace->users()->orderByPivot('created_at')->get(),
            'memberCount' => $workspace->users()->count(),
            'brandCount' => $workspace->brands()->count(),
            'creditBalance' => $workspace->spendableCredits(),
            'billingStatus' => match (true) {
                (bool) $workspace->billingSubscription()?->isSubscribed() => 'Plan active',
                $workspace->billingLocked() => 'Plan ended',
                default => 'Beta credits · no plan yet',
            },
            'canSeeCosts' => (bool) Membership::of(auth()->user(), $workspace)?->canSeeCosts(),
        ])->layout('components.layouts.app')->title('Settings');
    }
}
