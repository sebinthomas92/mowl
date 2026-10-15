<?php

namespace App\Http\Controllers;

use App\Models\CampaignGenerationJob;
use App\Services\CampaignJobRunner;
use App\Support\GenerationDeadline;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

class CampaignJobController extends Controller
{
    public function process(Request $request, CampaignGenerationJob $generationJob, CampaignJobRunner $runner): JsonResponse
    {
        abort_unless($generationJob->workspace->users()->whereKey($request->user()->id)->exists(), 404);

        return $this->run($generationJob, $runner);
    }

    public function recover(Request $request, CampaignJobRunner $runner): JsonResponse
    {
        abort_unless(
            config('campaigns.cron_secret') && hash_equals(config('campaigns.cron_secret'), (string) $request->bearerToken()),
            401,
        );

        CampaignGenerationJob::query()
            ->where('status', 'processing')
            ->where('updated_at', '<', now()->subMinutes(config('campaigns.stale_after_minutes')))
            ->update(['status' => 'retrying', 'phase' => 'retry_wait']);

        $generationJob = CampaignGenerationJob::query()
            ->whereIn('status', ['queued', 'retrying'])
            ->oldest()
            ->first();

        if (! $generationJob) {
            return response()->json(['status' => 'idle']);
        }

        return $this->run($generationJob, $runner);
    }

    private function run(CampaignGenerationJob $generationJob, CampaignJobRunner $runner): JsonResponse
    {
        if (! in_array($generationJob->status, ['queued', 'retrying'])) {
            return response()->json(['status' => $generationJob->status], 202);
        }

        app(GenerationDeadline::class)->start(config('campaigns.request_budget_seconds'));

        try {
            $generationJob = $runner->run($generationJob);

            return response()->json(['status' => $generationJob->status]);
        } catch (Throwable $exception) {
            report($exception);
            $generationJob->refresh();

            return response()->json([
                'status' => $generationJob->status,
                'retryable' => $generationJob->status === 'retrying',
            ], $generationJob->status === 'retrying' ? 503 : 422);
        } finally {
            app(GenerationDeadline::class)->clear();
        }
    }
}
