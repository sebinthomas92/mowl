<?php

namespace App\Services;

use App\Jobs\GenerateBannerCreative;
use App\Models\BannerCreative;
use Throwable;

class BannerJobRunner
{
    public function run(BannerCreative $creative): BannerCreative
    {
        try {
            app()->call([new GenerateBannerCreative($creative->id), 'handle']);
        } catch (Throwable $exception) {
            $creative->refresh();
            if ($creative->attempts >= (int) config('campaigns.banners.retry_attempts')) {
                (new GenerateBannerCreative($creative->id))->failed($exception);
            }

            throw $exception;
        }

        return $creative->refresh();
    }
}
