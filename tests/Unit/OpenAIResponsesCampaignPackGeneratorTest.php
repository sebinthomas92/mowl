<?php

namespace Tests\Unit;

use App\Models\Product;
use App\Models\SourceSnapshot;
use App\Services\OpenAIResponsesCampaignPackGenerator;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OpenAIResponsesCampaignPackGeneratorTest extends TestCase
{
    public function test_it_requests_strict_structured_output_and_maps_provider_usage(): void
    {
        config()->set('services.openai.api_key', 'test-key');
        config()->set('campaigns.openai.model', 'gpt-5.4-mini');
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
            ]),
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

        Http::assertSent(function (Request $request): bool {
            return $request->url() === 'https://api.openai.com/v1/responses'
                && $request['text']['format']['type'] === 'json_schema'
                && $request['text']['format']['strict'] === true
                && $request['model'] === 'gpt-5.4-mini';
        });
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
