<?php

namespace App\Http\Controllers;

use App\Models\BannerCreative;
use App\Services\BannerJobRunner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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
            ->where('updated_at', '<', now()->subMinutes(10))
            ->update(['status' => 'retrying']);
        $creative = BannerCreative::query()->whereIn('status', ['queued', 'retrying'])->oldest()->first();

        return $creative ? $this->run($creative, $runner) : response()->json(['status' => 'idle']);
    }

    private function run(BannerCreative $creative, BannerJobRunner $runner): JsonResponse
    {
        if (! in_array($creative->status, ['queued', 'retrying'])) {
            return response()->json(['status' => $creative->status], 202);
        }

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
        }
    }
}
