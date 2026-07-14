<?php

namespace App\Services;

use App\Jobs\GenerateBannerCreative;
use App\Models\BannerGenerationBatch;

class BannerJobDispatcher
{
    public function dispatch(BannerGenerationBatch $batch): void
    {
        if (config('campaigns.processing_mode') === 'request') {
            return;
        }

        $batch->creatives()->where('status', 'queued')->eachById(
            fn ($creative) => GenerateBannerCreative::dispatch($creative->id),
        );
    }
}
