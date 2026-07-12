<?php

namespace App\Concerns;

use App\Models\Workspace;

trait InteractsWithWorkspace
{
    protected function currentWorkspace(): Workspace
    {
        $workspaces = auth()->user()->workspaces();
        $workspaceId = session('current_workspace_id');
        $workspace = $workspaceId ? (clone $workspaces)->whereKey($workspaceId)->first() : null;
        $workspace ??= $workspaces->firstOrFail();
        session(['current_workspace_id' => $workspace->id]);

        return $workspace;
    }
}
