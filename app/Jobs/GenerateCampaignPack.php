<?php

namespace App\Jobs;

use App\Data\GenerationResult;
use App\Models\CampaignGenerationJob;
use App\Models\ProcessingCacheEntry;
use App\Services\CampaignGeneratorManager;
use App\Services\MediaProcessor;
use App\Services\ProductPageFetcher;
use App\Services\ProviderCostCalculator;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Throwable;

class GenerateCampaignPack implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $uniqueFor = 3600;

    public function __construct(public int $generationJobId)
    {
        $this->onQueue(config('campaigns.queue'));
    }

    public function uniqueId(): string
    {
        return (string) $this->generationJobId;
    }

    public function backoff(): array
    {
        return [10, 60, 300];
    }

    public function handle(ProductPageFetcher $fetcher, MediaProcessor $mediaProcessor, CampaignGeneratorManager $generators, ProviderCostCalculator $costs): void
    {
        $provider = $generators->providerName();
        $model = $generators->model();
        $claimed = CampaignGenerationJob::query()
            ->whereKey($this->generationJobId)
            ->whereIn('status', ['queued', 'retrying'])
            ->update([
                'status' => 'processing',
                'phase' => 'fetching_source',
                'provider' => $provider,
                'model' => $model,
                'attempts' => DB::raw('attempts + 1'),
                'started_at' => DB::raw('COALESCE(started_at, CURRENT_TIMESTAMP)'),
                'error_code' => null,
                'error_message' => null,
            ]);

        if ($claimed === 0) {
            return;
        }

        $job = CampaignGenerationJob::with(['campaignPack.product', 'sourceSnapshot'])->findOrFail($this->generationJobId);
        if (! $job->section) {
            $job->campaignPack->update(['status' => 'processing']);
        }

        try {
            $page = $fetcher->fetch($job->sourceSnapshot->url);
            $job->sourceSnapshot->update([
                'url' => $page['url'],
                'title' => $page['title'],
                'canonical_url' => $page['canonical_url'],
                'content_hash' => $page['content_hash'],
                'status' => 'ready',
                'extracted_content' => $page['content'],
                'extracted_truth' => $page['product_truth'],
                'error_message' => null,
                'fetched_at' => now(),
            ]);

            ProcessingCacheEntry::updateOrCreate(
                ['cache_key' => hash('sha256', 'source-extraction:v1:'.$page['content_hash'])],
                [
                    'stage' => 'source_extraction',
                    'content_hash' => $page['content_hash'],
                    'payload' => $page,
                ],
            );

            $job->update(['phase' => 'processing_media']);
            $page['media_analysis'] = $mediaProcessor->processForProduct($job->campaignPack->product);

            $job->update(['phase' => 'generating_pack']);
            if ($job->section) {
                $baseContent = $job->campaignPack->versions()->where('version', $job->base_version)->value('content') ?? [];
                $page['regeneration_section'] = $job->section;
                $page['current_content'] = $baseContent;
            }
            $cacheKey = hash('sha256', implode(':', [
                'campaign-pack:v1', $page['content_hash'], hash('sha256', json_encode([
                    $job->campaignPack->product->name,
                    $job->campaignPack->product->price,
                    $job->campaignPack->product->summary,
                ])), hash('sha256', json_encode(collect($page['media_analysis']['assets'] ?? [])->pluck('content_hash')->all())),
                $provider, $model ?: 'none', $job->analysis_mode, $job->section ?: 'full', $job->base_version ?: 0,
            ]));
            $cached = ProcessingCacheEntry::where('cache_key', $cacheKey)
                ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))
                ->first();

            if ($cached) {
                $result = new GenerationResult(
                    content: $cached->payload['content'],
                    evidence: $cached->payload['evidence'],
                    complianceFlags: $cached->payload['compliance_flags'],
                    provider: $provider,
                    model: $model,
                );
            } else {
                $result = $generators->driver()->generate($job->campaignPack->product, $job->sourceSnapshot, $page);
                ProcessingCacheEntry::create([
                    'cache_key' => $cacheKey,
                    'stage' => 'campaign_pack',
                    'content_hash' => $page['content_hash'],
                    'provider' => $result->provider,
                    'model' => $result->model,
                    'payload' => [
                        'content' => $result->content,
                        'evidence' => $result->evidence,
                        'compliance_flags' => $result->complianceFlags,
                    ],
                    'usage' => [
                        'input_tokens' => $result->inputTokens,
                        'cached_input_tokens' => $result->cachedInputTokens,
                        'output_tokens' => $result->outputTokens,
                    ],
                ]);
            }

            $cost = $result->provider === 'mock'
                ? 0.018
                : $costs->calculate($result->model ?? '', $result->inputTokens, $result->cachedInputTokens, $result->outputTokens);

            DB::transaction(function () use ($job, $result, $cost, $cached): void {
                $pack = $job->campaignPack()->lockForUpdate()->firstOrFail();
                $nextVersion = ((int) $pack->versions()->max('version')) + 1;
                $content = $job->section
                    ? $this->replaceSection(
                        $pack->versions()->where('version', $job->base_version)->value('content') ?? [],
                        $result->content,
                        $job->section,
                    )
                    : $result->content;
                $pack->versions()->create([
                    'version' => $nextVersion,
                    'content' => $content,
                    'evidence' => $result->evidence,
                    'compliance_flags' => $result->complianceFlags,
                    'generator' => $result->provider,
                ]);
                $pack->update([
                    'status' => 'approved',
                    'current_version' => $nextVersion,
                    'estimated_cost' => $cost,
                ]);
                $job->update([
                    'status' => 'completed',
                    'phase' => 'complete',
                    'provider' => $result->provider,
                    'model' => $result->model,
                    'cache_hit' => (bool) $cached,
                    'input_tokens' => $result->inputTokens,
                    'cached_input_tokens' => $result->cachedInputTokens,
                    'output_tokens' => $result->outputTokens,
                    'estimated_cost' => $cost,
                    'cost_alert' => $cost >= config('campaigns.cogs_alert'),
                    'provider_request_id' => $result->providerRequestId,
                    'completed_at' => now(),
                ]);
            });
        } catch (Throwable $exception) {
            $job->refresh()->update([
                'status' => 'retrying',
                'phase' => 'retry_wait',
                'error_code' => class_basename($exception),
                'error_message' => mb_substr($exception->getMessage(), 0, 2000),
            ]);
            if (! $job->section) {
                $job->sourceSnapshot->update([
                    'status' => 'failed',
                    'error_message' => mb_substr($exception->getMessage(), 0, 2000),
                ]);
            }

            throw $exception;
        }
    }

    public function failed(?Throwable $exception): void
    {
        $job = CampaignGenerationJob::find($this->generationJobId);
        if (! $job) {
            return;
        }

        DB::transaction(function () use ($job, $exception): void {
            $job->update([
                'status' => 'failed',
                'phase' => 'failed',
                'error_code' => $exception ? class_basename($exception) : $job->error_code,
                'error_message' => $exception ? mb_substr($exception->getMessage(), 0, 2000) : $job->error_message,
                'completed_at' => now(),
            ]);
            if (! $job->section) {
                $job->campaignPack()->update(['status' => 'failed']);
            }

            $alreadyRefunded = $job->workspace->credits()
                ->where('campaign_pack_id', $job->campaign_pack_id)
                ->where('event', 'generation_refund')
                ->exists();
            if (! $alreadyRefunded) {
                $job->workspace->credits()->create([
                    'campaign_pack_id' => $job->campaign_pack_id,
                    'amount' => $job->credit_cost,
                    'event' => 'generation_refund',
                    'description' => 'Pack credits returned after generation failure',
                ]);
            }
        });
    }

    private function replaceSection(array $current, array $generated, string $section): array
    {
        $keys = $section === 'positioning' ? ['audiences', 'benefits'] : [$section];
        foreach ($keys as $key) {
            if (array_key_exists($key, $generated)) {
                $current[$key] = $generated[$key];
            }
        }

        return $current;
    }
}
