<?php

namespace App\Concerns;

use App\Models\Workspace;

trait InteractsWithWorkspace
{
    protected function currentWorkspace(): Workspace
    {
        return auth()->user()->workspaces()->firstOrFail();
    }
}
