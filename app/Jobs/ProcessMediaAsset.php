<?php

namespace App\Jobs;

use App\Models\MediaAsset;
use App\Services\MediaProcessor;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ProcessMediaAsset implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $uniqueFor = 3600;

    public int $timeout;

    public function __construct(public int $mediaAssetId)
    {
        $this->timeout = config('campaigns.media.worker_timeout_seconds');
        $this->onQueue(config('campaigns.media.queue'));
    }

    public function uniqueId(): string
    {
        return (string) $this->mediaAssetId;
    }

    public function backoff(): array
    {
        return [15, 90, 300];
    }

    public function handle(MediaProcessor $processor): void
    {
        $asset = MediaAsset::find($this->mediaAssetId);
        if (! $asset || $asset->status === 'processed') {
            return;
        }

        $processor->process($asset);
    }
}
