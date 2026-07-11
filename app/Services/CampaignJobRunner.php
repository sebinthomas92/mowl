<?php

namespace App\Services;

use App\Jobs\GenerateCampaignPack;
use App\Models\CampaignGenerationJob;
use Throwable;

class CampaignJobRunner
{
    public function run(CampaignGenerationJob $generationJob): CampaignGenerationJob
    {
        try {
            app()->call([new GenerateCampaignPack($generationJob->id), 'handle']);
        } catch (Throwable $exception) {
            $generationJob->refresh();

            if ($generationJob->attempts >= 3) {
                (new GenerateCampaignPack($generationJob->id))->failed($exception);
            }

            throw $exception;
        }

        return $generationJob->refresh();
    }
}
