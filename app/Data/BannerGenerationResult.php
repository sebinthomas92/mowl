<?php

namespace App\Data;

readonly class BannerGenerationResult
{
    public function __construct(
        public string $imageBytes,
        public string $mimeType,
        public string $provider,
        public ?string $model = null,
        public int $inputTokens = 0,
        public int $outputTextTokens = 0,
        public int $outputImageTokens = 0,
        public ?string $providerRequestId = null,
        public ?int $providerLatencyMs = null,
    ) {}
}
