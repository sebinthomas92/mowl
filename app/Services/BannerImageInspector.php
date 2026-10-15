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

    /** @return array{marks: list<string>, input_tokens: int, output_tokens: int} */
    public function inspect(string $imageBytes, string $mimeType, string $product, string $brand): array
    {
        $prompt = "This is an advertising background for the product \"{$product}\" by the brand \"{$brand}\". "
            .'List every piece of readable text or recognisable mark in the image: book or e-book covers and titles (including any shown on a device screen), '
            .'author names, magazine or newspaper names, book spines, labels, packaging text, logos and brand names. '
            .'Do not list blank, blurred or illegible shapes, and do not list text printed on the product itself that is the brand above. '
            .'Return JSON only.';
        [$response] = $this->client->generateContent([
            'contents' => [['role' => 'user', 'parts' => [
                ['text' => $prompt],
                ['inlineData' => ['mimeType' => $mimeType, 'data' => base64_encode($imageBytes)]],
            ]]],
            'generationConfig' => [
                'temperature' => 0,
                'responseMimeType' => 'application/json',
                'responseSchema' => [
                    'type' => 'OBJECT',
                    'properties' => ['marks' => ['type' => 'ARRAY', 'items' => ['type' => 'STRING']]],
                    'required' => ['marks'],
                ],
            ],
        ], config('campaigns.google.model'));

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
