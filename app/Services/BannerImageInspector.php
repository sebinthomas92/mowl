<?php

namespace App\Services;

use App\Exceptions\VertexAIResponseException;

/**
 * Looks at a generated banner background for readable third-party text: book covers on a screen,
 * magazine spines, labels, logos. The image prompt asks for none, but the model copies what the
 * product reference shows, so the prompt alone does not hold.
 */
class BannerImageInspector
{
    public function __construct(private GoogleVertexAIClient $client) {}

    /**
     * @param  array{mimeType: string, data: string}|null  $reference  the product's own photo, so text printed on the product itself is not flagged
     * @return array{marks: list<string>, input_tokens: int, output_tokens: int}
     */
    public function inspect(string $imageBytes, string $mimeType, string $product, string $brand, ?array $reference = null): array
    {
        $prompt = "The first image is an advertising background for the product \"{$product}\" by the brand \"{$brand}\". "
            .'List every piece of readable text or recognisable mark in it that does not belong to the product itself: book or e-book covers and titles, '
            .'author names, magazine or newspaper names, book spines, labels and packaging of other items, logos and other brands\' names. '
            .($reference ? 'The second image is the real product photo: do not list text or marks printed on the product or its own packaging as it appears there. ' : 'Do not list the brand above where it is printed on the product itself. ')
            .'Any content on a device screen (a phone, tablet or e-reader) counts, even when the product photo shows it. '
            .'Do not list blank, blurred or illegible shapes. Return JSON only.';
        $parts = [
            ['text' => $prompt],
            ['inlineData' => ['mimeType' => $mimeType, 'data' => base64_encode($imageBytes)]],
        ];
        if ($reference) {
            $parts[] = ['inlineData' => $reference];
        }
        [$response] = $this->client->generateContent([
            'contents' => [['role' => 'user', 'parts' => $parts]],
            'generationConfig' => [
                'temperature' => 0,
                'responseMimeType' => 'application/json',
                'responseSchema' => [
                    'type' => 'OBJECT',
                    'properties' => ['marks' => ['type' => 'ARRAY', 'items' => ['type' => 'STRING']]],
                    'required' => ['marks'],
                ],
            ],
        ], config('campaigns.google.model'), (int) config('campaigns.banners.inspection_timeout_seconds', 45));

        $json = $response->json();
        $decoded = json_decode((string) data_get($json, 'candidates.0.content.parts.0.text'), true);
        if (! is_array($decoded) || ! is_array($decoded['marks'] ?? null)) {
            throw new VertexAIResponseException('vertex_invalid_inspection', 'Vertex AI returned no banner image inspection.', true);
        }

        return [
            'marks' => array_values(array_filter(array_map(fn ($mark) => is_string($mark) ? trim($mark) : '', $decoded['marks']))),
            'input_tokens' => (int) data_get($json, 'usageMetadata.promptTokenCount', 0),
            'output_tokens' => (int) data_get($json, 'usageMetadata.candidatesTokenCount', 0),
        ];
    }
}
