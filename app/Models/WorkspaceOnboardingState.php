<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkspaceOnboardingState extends Model
{
    protected $fillable = ['workspace_id', 'completed_steps'];

    protected function casts(): array
    {
        return ['completed_steps' => 'array'];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }
}
