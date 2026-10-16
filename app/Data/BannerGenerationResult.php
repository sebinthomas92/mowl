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
        // Spend outside the image model's own token prices, such as the background inspection.
        public float $additionalCost = 0.0,
        // The product cut out of its store photo (PNG with transparency), composited onto the scene.
        public ?string $productCutout = null,
    ) {}
}
