<?php

namespace App\Services;

use App\Jobs\GenerateCampaignPack;

class CampaignJobDispatcher
{
    public function dispatch(int $generationJobId): void
    {
        if ($this->usesRequestProcessing()) {
            return;
        }

        GenerateCampaignPack::dispatch($generationJobId);
    }

    public function usesRequestProcessing(): bool
    {
        return config('campaigns.processing_mode') === 'request';
    }
}
