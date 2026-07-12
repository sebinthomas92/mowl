<?php

namespace App\Data;

final readonly class GenerationResult
{
    public function __construct(
        public array $content,
        public array $evidence,
        public array $complianceFlags,
        public string $provider,
        public ?string $model,
        public int $inputTokens = 0,
        public int $cachedInputTokens = 0,
        public int $outputTokens = 0,
        public ?string $providerRequestId = null,
        public ?int $providerLatencyMs = null,
    ) {}
}
