<?php

namespace Tests\Unit;

use App\Exceptions\OpenAIResponseException;
use App\Models\Product;
use App\Models\SourceSnapshot;
use App\Services\OpenAIResponsesCampaignPackGenerator;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OpenAIResponsesCampaignPackGeneratorTest extends TestCase
{
    public function test_it_requests_strict_structured_output_and_maps_provider_usage(): void
    {
        config()->set('services.openai.api_key', 'test-key');
        config()->set('campaigns.openai.model', 'gpt-5.4-mini');
        config()->set('campaigns.openai.retry_backoff_ms', [0, 0]);
        $output = $this->campaignOutput();
        Http::fake([
            'api.openai.com/v1/responses' => Http::response([
                'id' => 'resp_test_123',
                'output' => [[
                    'type' => 'message',
                    'content' => [['type' => 'output_text', 'text' => json_encode($output)]],
                ]],
                'usage' => [
                    'input_tokens' => 1200,
                    'input_tokens_details' => ['cached_tokens' => 200],
                    'output_tokens' => 500,
                ],
            ], 200, ['x-request-id' => 'req_test_123']),
        ]);

        $result = app(OpenAIResponsesCampaignPackGenerator::class)->generate(
            new Product(['name' => 'Canvas Tote', 'price' => '$89']),
            new SourceSnapshot(['url' => 'https://example.com/tote']),
            ['description' => 'Everyday tote', 'content' => 'A roomy canvas tote.', 'product_truth' => ['name' => 'Canvas Tote']],
        );

        $this->assertSame('openai', $result->provider);
        $this->assertSame('gpt-5.4-mini', $result->model);
        $this->assertSame(1200, $result->inputTokens);
        $this->assertSame(200, $result->cachedInputTokens);
        $this->assertSame('A practical tote.', $result->content['direction']['title']);
        $this->assertCount(1, $result->evidence);
        $this->assertSame('req_test_123', $result->providerRequestId);
        $this->assertNotNull($result->providerLatencyMs);

        Http::assertSent(function (Request $request): bool {
            return $request->url() === 'https://api.openai.com/v1/responses'
                && $request['text']['format']['type'] === 'json_schema'
                && $request['text']['format']['strict'] === true
                && $request['text']['format']['schema']['additionalProperties'] === false
                && $request['store'] === false
                && $request['model'] === 'gpt-5.4-mini';
        });
    }

    public function test_it_retries_a_rate_limited_response_with_the_same_structured_schema(): void
    {
        $this->configureGenerator();
        Http::fakeSequence()
            ->push(['error' => ['message' => 'Slow down']], 429)
            ->push($this->responsePayload($this->campaignOutput()));

        $result = $this->generate();

        $this->assertSame('openai', $result->provider);
        Http::assertSentCount(2);
        Http::assertSent(fn (Request $request) => $request['text']['format']['strict'] === true);
    }

    public function test_it_uses_vercel_oidc_only_for_the_ai_gateway(): void
    {
        config()->set('services.openai.api_key', null);
        config()->set('services.ai_gateway.api_key', null);
        config()->set('services.ai_gateway.oidc_token', 'gateway-oidc-token');
        config()->set('campaigns.openai.base_url', 'https://ai-gateway.vercel.sh/v1');
        config()->set('campaigns.openai.retry_attempts', 1);
        Http::fake(['ai-gateway.vercel.sh/v1/responses' => Http::response($this->responsePayload($this->campaignOutput()))]);

        $this->assertSame('openai', $this->generate()->provider);
        Http::assertSent(fn (Request $request) => $request->hasHeader('Authorization', 'Bearer gateway-oidc-token') && $request->url() === 'https://ai-gateway.vercel.sh/v1/responses');
    }

    public function test_it_uses_a_configured_model_fallback_after_a_provider_error(): void
    {
        $this->configureGenerator();
        config()->set('campaigns.openai.retry_attempts', 1);
        config()->set('campaigns.openai.fallback_models', ['gpt-5.4-mini-fallback']);
        Http::fakeSequence()
            ->push(['error' => ['message' => 'Temporary failure']], 500)
            ->push($this->responsePayload($this->campaignOutput()));

        $result = $this->generate();

        $this->assertSame('gpt-5.4-mini', $result->model);
        $this->assertSame(['gpt-5.4-mini', 'gpt-5.4-mini-fallback'], collect(Http::recorded())->pluck(0)->map(fn (Request $request) => $request['model'])->all());
    }

    public function test_it_retries_malformed_structured_output(): void
    {
        $this->configureGenerator();
        $invalid = $this->campaignOutput();
        unset($invalid['meta']);
        Http::fakeSequence()
            ->push($this->responsePayload($invalid))
            ->push($this->responsePayload($this->campaignOutput()));

        $result = $this->generate();

        $this->assertSame('A practical tote.', $result->content['direction']['title']);
        Http::assertSentCount(2);
    }

    public function test_it_rejects_a_refusal_without_retrying(): void
    {
        $this->configureGenerator();
        config()->set('campaigns.openai.fallback_models', ['gpt-5.4-mini-fallback']);
        Http::fake([
            'api.openai.com/v1/responses' => Http::response([
                'output' => [[
                    'type' => 'message',
                    'content' => [['type' => 'refusal', 'refusal' => 'I cannot help with that request.']],
                ]],
            ]),
        ]);

        try {
            $this->generate();
            $this->fail('Expected the provider refusal to be surfaced.');
        } catch (OpenAIResponseException $exception) {
            $this->assertSame('openai_refusal', $exception->errorCode);
            $this->assertStringContainsString('declined', $exception->getMessage());
        }

        Http::assertSentCount(1);
    }

    public function test_it_retries_a_connection_timeout(): void
    {
        $this->configureGenerator();
        $attempt = 0;
        Http::fake(function () use (&$attempt) {
            if (++$attempt === 1) {
                throw new ConnectionException('Timed out');
            }

            return Http::response($this->responsePayload($this->campaignOutput()));
        });

        $this->assertSame('openai', $this->generate()->provider);
        $this->assertSame(2, $attempt);
    }

    public function test_it_rejects_an_unsupported_claim_that_is_not_flagged(): void
    {
        $this->configureGenerator();
        $output = $this->campaignOutput();
        $output['evidence'][] = ['claim' => 'Waterproof', 'source' => '', 'excerpt' => '', 'status' => 'unsupported'];
        Http::fake(['api.openai.com/v1/responses' => Http::response($this->responsePayload($output))]);

        $this->expectException(OpenAIResponseException::class);
        $this->expectExceptionMessage('unsupported claim without a compliance flag');
        $this->generate();
    }

    private function configureGenerator(): void
    {
        config()->set('services.openai.api_key', 'test-key');
        config()->set('campaigns.openai.model', 'gpt-5.4-mini');
        config()->set('campaigns.openai.fallback_models', []);
        config()->set('campaigns.openai.retry_attempts', 2);
        config()->set('campaigns.openai.retry_backoff_ms', [0, 0]);
    }

    private function generate()
    {
        return app(OpenAIResponsesCampaignPackGenerator::class)->generate(
            new Product(['name' => 'Canvas Tote', 'price' => '$89']),
            new SourceSnapshot(['url' => 'https://example.com/tote']),
            ['description' => 'Everyday tote', 'content' => 'A roomy canvas tote.', 'product_truth' => ['name' => 'Canvas Tote']],
        );
    }

    private function responsePayload(array $output): array
    {
        return [
            'id' => 'resp_test_123',
            'model' => 'gpt-5.4-mini',
            'output' => [[
                'type' => 'message',
                'content' => [['type' => 'output_text', 'text' => json_encode($output)]],
            ]],
            'usage' => [
                'input_tokens' => 1200,
                'input_tokens_details' => ['cached_tokens' => 200],
                'output_tokens' => 500,
            ],
        ];
    }

    private function campaignOutput(): array
    {
        return [
            'product_truth' => ['name' => 'Canvas Tote', 'price' => '$89', 'source' => 'https://example.com/tote', 'verified_facts' => ['Roomy canvas tote']],
            'direction' => ['title' => 'A practical tote.', 'summary' => 'An everyday carry direction.'],
            'audiences' => ['Everyday commuters'],
            'benefits' => ['Roomy carry'],
            'meta' => ['primary_text' => 'Carry the everyday.', 'headlines' => ['A practical tote'], 'descriptions' => ['Explore the tote.']],
            'hooks' => ['Meet your everyday carry.'],
            'script' => [['time' => '0:00–0:03', 'line' => 'Meet the tote.']],
            'captions' => ['Everyday carry.'],
            'shot_log' => ['0–3s: Product reveal'],
            'evidence' => [['claim' => 'Roomy canvas tote', 'source' => 'https://example.com/tote', 'excerpt' => 'A roomy canvas tote.', 'status' => 'source-linked']],
            'compliance_flags' => [],
        ];
    }
}
