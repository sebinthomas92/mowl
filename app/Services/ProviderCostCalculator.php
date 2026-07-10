<?php

namespace App\Services;

class ProviderCostCalculator
{
    public function calculate(string $model, int $inputTokens, int $cachedInputTokens, int $outputTokens): float
    {
        $prices = config('campaigns.openai.prices_per_million')[$model] ?? null;

        if (! $prices) {
            return 0.0;
        }

        $uncachedInput = max(0, $inputTokens - $cachedInputTokens);

        return round(
            (($uncachedInput * $prices['input']) + ($cachedInputTokens * $prices['cached_input']) + ($outputTokens * $prices['output'])) / 1_000_000,
            6,
        );
    }
}
