<?php

namespace Tests\Unit;

use App\Services\ProviderCostCalculator;
use Tests\TestCase;

class ProviderCostCalculatorTest extends TestCase
{
    public function test_it_accounts_for_cached_and_uncached_tokens(): void
    {
        $cost = app(ProviderCostCalculator::class)->calculate('gpt-5.4-mini', 10_000, 4_000, 2_000);

        $this->assertSame(0.0138, $cost);
    }

    public function test_unknown_models_do_not_create_invented_costs(): void
    {
        $this->assertSame(0.0, app(ProviderCostCalculator::class)->calculate('unknown-model', 1000, 0, 1000));
    }
}
