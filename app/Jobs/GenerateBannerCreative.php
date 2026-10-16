<?php

namespace App\Jobs;

use App\Exceptions\BannerAttemptFailed;
use App\Exceptions\VertexAIResponseException;
use App\Models\BannerCreative;
use App\Services\BannerBatchStatus;
use App\Services\BannerComposer;
use App\Services\BannerGeneratorManager;
use App\Services\ContentLibrary;
use App\Services\ProviderCostCalculator;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

class GenerateBannerCreative implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries;

    public int $uniqueFor = 3600;

    public function __construct(public int $bannerCreativeId)
    {
        $this->tries = (int) config('campaigns.banners.retry_attempts');
        $this->onQueue(config('campaigns.queue'));
    }

    public function uniqueId(): string
    {
        return (string) $this->bannerCreativeId;
    }

    public function backoff(): array
    {
        return [10, 60, 300];
    }

    public function handle(BannerGeneratorManager $generators, BannerComposer $composer, ProviderCostCalculator $costs, BannerBatchStatus $batchStatus): void
    {
        $claimed = BannerCreative::query()
            ->whereKey($this->bannerCreativeId)
            ->whereIn('status', ['queued', 'retrying'])
            ->update([
                'status' => 'processing',
                'attempts' => DB::raw('attempts + 1'),
                'started_at' => DB::raw('COALESCE(started_at, CURRENT_TIMESTAMP)'),
                'error_code' => null,
                'error_message' => null,
            ]);
        if ($claimed === 0) {
            return;
        }

        $creative = BannerCreative::with(['batch.workspace', 'campaignPack.product.brand'])->findOrFail($this->bannerCreativeId);
        $creative->batch->update(['status' => 'running', 'started_at' => $creative->batch->started_at ?: now()]);

        try {
            $images = $creative->campaignPack->product->mediaAssets()
                ->where('type', 'image')
                ->where('metadata->origin', 'product_page')
                ->oldest()
                ->limit((int) config('campaigns.banners.max_input_images'))
                ->get();
            if ($images->isEmpty()) {
                throw new \RuntimeException('No product image is available for banner generation.');
            }

            $result = $generators->driver()->generate($creative, $images);
            $cost = $result->provider === 'mock'
                ? 0.0
                : round($costs->calculateBanner($result->inputTokens, $result->outputTextTokens, $result->outputImageTokens) + $result->additionalCost, 6);
            // Earlier attempts whose backgrounds were rejected already recorded their spend.
            $cost = round((float) $creative->estimated_cost + $cost, 6);
            $basePath = "campaign-banners/{$creative->batch->workspace_id}/{$creative->campaign_pack_id}/{$creative->id}";
            $backgroundPath = $basePath.'/background.'.($result->mimeType === 'image/webp' ? 'webp' : 'png');
            Storage::disk($creative->disk)->put($backgroundPath, $result->imageBytes);
            $final = $composer->compose($result->imageBytes, $creative->campaignPack->product->brand, $creative, $result->productCutout);
            $outputPath = $basePath.'/banner.png';
            Storage::disk($creative->disk)->put($outputPath, $final);

            $creative->update([
                'status' => 'completed',
                'background_path' => $backgroundPath,
                'output_path' => $outputPath,
                'output_mime_type' => 'image/png',
                'width' => config('campaigns.banners.width'),
                'height' => config('campaigns.banners.height'),
                'size_bytes' => strlen($final),
                'content_hash' => hash('sha256', $final),
                'provider' => $result->provider,
                'model' => $result->model,
                'input_tokens' => $result->inputTokens,
                'output_text_tokens' => $result->outputTextTokens,
                'output_image_tokens' => $result->outputImageTokens,
                'estimated_cost' => $cost,
                'provider_request_id' => $result->providerRequestId,
                'provider_latency_ms' => $result->providerLatencyMs,
                'completed_at' => now(),
            ]);
            $batchStatus->refresh($creative->batch->fresh());
            $creative->batch->workspace->auditEvents()->create([
                'actor_user_id' => $creative->batch->requested_by_user_id,
                'event' => 'campaign_banner_generated',
                'subject_type' => BannerCreative::class,
                'subject_id' => $creative->id,
                'metadata' => ['campaign_pack_id' => $creative->campaign_pack_id, 'provider' => $result->provider, 'estimated_cost' => $cost],
            ]);
        } catch (Throwable $exception) {
            $creative->update([
                'status' => 'retrying',
                'estimated_cost' => round((float) $creative->estimated_cost + ($exception instanceof BannerAttemptFailed ? $exception->spentCost : 0.0), 6),
                'error_code' => $exception instanceof VertexAIResponseException || $exception instanceof BannerAttemptFailed ? $exception->errorCode : class_basename($exception),
                'error_message' => mb_substr($exception->getMessage().$this->keepRejected($creative, $exception), 0, 2000),
            ]);
            $batchStatus->refresh($creative->batch->fresh());

            throw $exception;
        }

        $this->syncContent($creative);
    }

    /** Stores the last rejected background (never shown to clients) so a rejection can be checked by eye. */
    private function keepRejected(BannerCreative $creative, Throwable $exception): string
    {
        if (! $exception instanceof BannerAttemptFailed || $exception->rejectedImage === null) {
            return '';
        }
        $path = "campaign-banners/{$creative->batch->workspace_id}/{$creative->campaign_pack_id}/{$creative->id}/rejected-{$creative->attempts}.png";
        try {
            return Storage::disk($creative->disk)->put($path, $exception->rejectedImage) !== false ? " (rejected background: {$path})" : '';
        } catch (Throwable $storageError) {
            report($storageError);

            return '';
        }
    }

    /** The finished banner becomes a content item; a sync failure must not fail the banner. */
    private function syncContent(BannerCreative $creative): void
    {
        if (! ContentLibrary::available()) {
            return;
        }
        try {
            app(ContentLibrary::class)->syncPack($creative->campaignPack->fresh());
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    public function failed(?Throwable $exception): void
    {
        $creative = BannerCreative::with('batch.workspace')->find($this->bannerCreativeId);
        if (! $creative) {
            return;
        }

        DB::transaction(function () use ($creative, $exception): void {
            $firstTerminalFailure = $creative->status !== 'failed';
            $creative->update([
                'status' => 'failed',
                'error_code' => $exception instanceof VertexAIResponseException || $exception instanceof BannerAttemptFailed ? $exception->errorCode : ($exception ? class_basename($exception) : $creative->error_code),
                // The attempt already wrote this message, possibly with the rejected background's path appended.
                'error_message' => $exception && ! str_starts_with((string) $creative->error_message, mb_substr($exception->getMessage(), 0, 2000)) ? mb_substr($exception->getMessage(), 0, 2000) : $creative->error_message,
                'completed_at' => now(),
            ]);
            app(BannerBatchStatus::class)->refresh($creative->batch->fresh());

            if ($firstTerminalFailure) {
                $creative->batch->workspace->auditEvents()->create([
                    'actor_user_id' => $creative->batch->requested_by_user_id,
                    'event' => 'campaign_banner_failed',
                    'subject_type' => BannerCreative::class,
                    'subject_id' => $creative->id,
                    'metadata' => ['campaign_pack_id' => $creative->campaign_pack_id, 'attempts' => $creative->attempts],
                ]);
            }

            if ($creative->batch->kind === 'additional') {
                $refund = $creative->batch->workspace->credits()->firstOrCreate(
                    ['banner_generation_batch_id' => $creative->batch->id, 'event' => 'banner_generation_refund'],
                    [
                        'campaign_pack_id' => $creative->campaign_pack_id,
                        'amount' => $creative->batch->credit_cost,
                        'description' => 'Banner credit returned after final generation failure',
                        'idempotency_key' => 'banner-refund:'.$creative->batch->id,
                    ],
                );
                if ($refund->wasRecentlyCreated) {
                    $creative->batch->workspace->auditEvents()->create([
                        'actor_user_id' => $creative->batch->requested_by_user_id,
                        'event' => 'campaign_banner_credit_refunded',
                        'subject_type' => $creative->batch::class,
                        'subject_id' => $creative->batch->id,
                        'metadata' => ['campaign_pack_id' => $creative->campaign_pack_id, 'credits' => $creative->batch->credit_cost],
                    ]);
                }
            }
        });
    }
}
