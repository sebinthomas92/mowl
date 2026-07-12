<?php

namespace App\Services;

use App\Jobs\ProcessMediaAsset;

class MediaJobDispatcher
{
    public function dispatch(int $mediaAssetId): void
    {
        ProcessMediaAsset::dispatch($mediaAssetId);
    }
}
