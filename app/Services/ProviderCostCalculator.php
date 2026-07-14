<?php

namespace App\Services;

class ProviderCostCalculator
{
    public function calculate(string $model, int $inputTokens, int $cachedInputTokens, int $outputTokens): float
    {
        $prices = config('campaigns.openai.prices_per_million')[$model]
            ?? config('campaigns.google.prices_per_million')[$model]
            ?? null;

        if (! $prices) {
            return 0.0;
        }

        $uncachedInput = max(0, $inputTokens - $cachedInputTokens);

        return round(
            (($uncachedInput * $prices['input']) + ($cachedInputTokens * $prices['cached_input']) + ($outputTokens * $prices['output'])) / 1_000_000,
            6,
        );
    }

    public function calculateBanner(int $inputTokens, int $outputTextTokens, int $outputImageTokens): float
    {
        $prices = config('campaigns.banners.prices_per_million');

        return round((
            ($inputTokens * $prices['input'])
            + ($outputTextTokens * $prices['output_text'])
            + ($outputImageTokens * $prices['output_image'])
        ) / 1_000_000, 6);
    }
}
