<?php

namespace App\Http\Controllers;

use App\Models\BannerCreative;
use App\Services\BannerJobRunner;
use App\Support\GenerationDeadline;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Sleep;
use Throwable;

class BannerGenerationController extends Controller
{
    public function process(Request $request, BannerCreative $bannerCreative, BannerJobRunner $runner): JsonResponse
    {
        abort_unless($bannerCreative->batch->workspace->users()->whereKey($request->user()->id)->exists(), 404);

        return $this->run($bannerCreative, $runner);
    }

    public function recover(Request $request, BannerJobRunner $runner): JsonResponse
    {
        abort_unless(
            config('campaigns.cron_secret') && hash_equals(config('campaigns.cron_secret'), (string) $request->bearerToken()),
            401,
        );
        BannerCreative::query()
            ->where('status', 'processing')
            ->where('updated_at', '<', now()->subMinutes(config('campaigns.stale_after_minutes')))
            ->update(['status' => 'retrying']);
        $creative = BannerCreative::query()->whereIn('status', ['queued', 'retrying'])->oldest()->first();

        return $creative ? $this->run($creative, $runner) : response()->json(['status' => 'idle']);
    }

    private function run(BannerCreative $creative, BannerJobRunner $runner): JsonResponse
    {
        if (! in_array($creative->status, ['queued', 'retrying'])) {
            return response()->json(['status' => $creative->status], 202);
        }

        // A retry right after a failure (often a Vertex 429) would hit the same limit, so it waits out
        // the rest of the pause here. The page re-runs the banner as soon as the last attempt returns,
        // and a timer in the page did not survive its 3-second polling.
        if ($creative->status === 'retrying') {
            $wait = (int) config('campaigns.banners.retry_pause_seconds') - (int) $creative->updated_at->diffInSeconds(now(), true);
            if ($wait > 0) {
                Sleep::for($wait)->seconds();
            }
        }

        app(GenerationDeadline::class)->start(config('campaigns.request_budget_seconds'));

        try {
            $creative = $runner->run($creative);

            return response()->json(['status' => $creative->status]);
        } catch (Throwable $exception) {
            report($exception);
            $creative->refresh();

            return response()->json([
                'status' => $creative->status,
                'retryable' => $creative->status === 'retrying',
            ], $creative->status === 'retrying' ? 503 : 422);
        } finally {
            app(GenerationDeadline::class)->clear();
        }
    }
}
