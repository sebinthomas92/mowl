<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One AI run on a page: the whole page written from the product brief, or one section rewritten. */
class PageGeneration extends Model
{
    protected $fillable = [
        'workspace_id', 'page_id', 'user_id', 'kind', 'status', 'instruction', 'target', 'base_revision', 'result_revision',
        'model', 'input_tokens', 'output_tokens', 'cost_usd', 'credit_cost', 'error', 'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'base_revision' => 'integer', 'result_revision' => 'integer', 'input_tokens' => 'integer', 'output_tokens' => 'integer',
            'cost_usd' => 'decimal:5', 'credit_cost' => 'integer', 'finished_at' => 'datetime',
        ];
    }

    public function page(): BelongsTo
    {
        return $this->belongsTo(Page::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
