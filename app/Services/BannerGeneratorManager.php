<?php

namespace App\Services;

use App\Contracts\BannerGenerator;
use InvalidArgumentException;

class BannerGeneratorManager
{
    public function driver(): BannerGenerator
    {
        return match ($this->providerName()) {
            'mock' => app(MockBannerGenerator::class),
            'google' => app(GoogleVertexBannerGenerator::class),
            default => throw new InvalidArgumentException('Unsupported banner generator: '.$this->providerName()),
        };
    }

    public function providerName(): string
    {
        return (string) config('campaigns.banners.generator', 'mock');
    }

    public function model(): ?string
    {
        return $this->providerName() === 'google'
            ? (string) config('campaigns.banners.model')
            : null;
    }
}
