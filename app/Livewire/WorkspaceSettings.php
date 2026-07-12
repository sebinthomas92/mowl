<?php

namespace App\Livewire;

use App\Concerns\InteractsWithWorkspace;
use App\Models\Workspace;
use App\Models\WorkspaceAuditEvent;
use Illuminate\Support\Facades\DB;
use Livewire\Component;

class WorkspaceSettings extends Component
{
    use InteractsWithWorkspace;

    public string $name = '';

    public function mount(): void
    {
        $this->name = $this->currentWorkspace()->name;
    }

    public function save(): void
    {
        $workspace = $this->currentWorkspace();
        $this->authorizeOwner($workspace);
        $data = $this->validate(['name' => ['required', 'string', 'max:120']]);

        DB::transaction(function () use ($workspace, $data): void {
            $lockedWorkspace = Workspace::query()->lockForUpdate()->findOrFail($workspace->id);
            $previousName = $lockedWorkspace->name;
            $lockedWorkspace->update(['name' => $data['name']]);

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
            'isOwner' => $membership->pivot->role === 'owner',
            'owner' => $workspace->users()->wherePivot('role', 'owner')->first(),
            'members' => $workspace->users()->orderByPivot('created_at')->get(),
            'memberCount' => $workspace->users()->count(),
            'brandCount' => $workspace->brands()->count(),
            'creditBalance' => $workspace->creditBalance(),
            'billingConnected' => false,
        ])->layout('components.layouts.app');
    }
}
