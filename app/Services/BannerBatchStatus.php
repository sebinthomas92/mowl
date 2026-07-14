<?php

namespace App\Services;

use App\Models\BannerGenerationBatch;

class BannerBatchStatus
{
    public function refresh(BannerGenerationBatch $batch): void
    {
        $creatives = $batch->creatives()->get();
        $completed = $creatives->where('status', 'completed')->count();
        $failed = $creatives->where('status', 'failed')->count();
        $active = $creatives->whereIn('status', ['queued', 'processing', 'retrying'])->count();
        $status = match (true) {
            $completed === $batch->requested_count => 'completed',
            $active > 0 && $creatives->contains('status', 'processing') => 'running',
            $active > 0 && $creatives->contains('status', 'retrying') => 'retrying',
            $active > 0 => 'queued',
            $completed > 0 && $failed > 0 => 'partial',
            default => 'failed',
        };

        $batch->update([
            'status' => $status,
            'provider' => $creatives->pluck('provider')->filter()->last(),
            'model' => $creatives->pluck('model')->filter()->last(),
            'input_tokens' => $creatives->sum('input_tokens'),
            'output_text_tokens' => $creatives->sum('output_text_tokens'),
            'output_image_tokens' => $creatives->sum('output_image_tokens'),
            'estimated_cost' => $creatives->sum(fn ($creative) => (float) $creative->estimated_cost),
            'cost_alert' => $creatives->sum(fn ($creative) => (float) $creative->estimated_cost) >= config('campaigns.cogs_alert'),
            'started_at' => $batch->started_at ?: ($creatives->min('started_at') ?: null),
            'completed_at' => in_array($status, ['completed', 'partial', 'failed']) ? now() : null,
            'error_code' => $status === 'failed' ? $creatives->pluck('error_code')->filter()->last() : null,
            'error_message' => in_array($status, ['partial', 'failed']) ? $creatives->pluck('error_message')->filter()->last() : null,
        ]);
    }
}
