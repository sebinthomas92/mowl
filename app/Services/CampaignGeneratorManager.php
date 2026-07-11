<?php

namespace App\Services;

use App\Contracts\CampaignPackGenerator;
use InvalidArgumentException;

class CampaignGeneratorManager
{
    public function driver(?string $name = null): CampaignPackGenerator
    {
        return match ($name ?? config('campaigns.generator')) {
            'mock' => app(MockCampaignPackGenerator::class),
            'openai' => app(OpenAIResponsesCampaignPackGenerator::class),
            default => throw new InvalidArgumentException('Unsupported campaign generator.'),
        };
    }

    public function providerName(?string $name = null): string
    {
        return $name ?? config('campaigns.generator');
    }

    public function model(?string $name = null): ?string
    {
        return $this->providerName($name) === 'openai' ? config('campaigns.openai.model') : null;
    }
}
