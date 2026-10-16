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
     * Only the generated image is sent. When the product photo was sent alongside it, the model listed
     * the photo's own props (the book, Kindle and magazine on the Kindle Stand page) as if they were in
     * the background, so every round of pack 20 was rejected.
     *
     * @return array{marks: list<string>, input_tokens: int, output_tokens: int}
     */
    public function inspect(string $imageBytes, string $mimeType, string $product, string $brand): array
    {
        $prompt = "This is an advertising background for the product \"{$product}\" by the brand \"{$brand}\". "
            .'List every piece of readable text or recognisable mark in this image: book or e-book covers and titles, '
            .'author names, magazine or newspaper names, book spines, labels and packaging of other items, logos and other brands\' names, '
            .'and anything shown on a device screen (a phone, tablet or e-reader). '
            .'Do not list the brand or product name above where it is printed on the product itself. '
            .'Do not list blank, blurred or illegible shapes. Only report what is visible in this image. Return JSON only.';
        [$mimeType, $imageBytes] = $this->smaller($mimeType, $imageBytes);
        $parts = [
            ['text' => $prompt],
            ['inlineData' => ['mimeType' => $mimeType, 'data' => base64_encode($imageBytes)]],
        ];
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
        ], config('campaigns.google.model'), (int) config('campaigns.banners.inspection_timeout_seconds', 30));

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

    /**
     * Checks the product in the generated image against the store's own photo. The photo is styled
     * (the Kindle Stand page shows a book and a Kindle on the stand), and the image model has drawn a
     * real paper book in place of a sculpted stand, so props are named as props and material counts.
     *
     * @param  array{mimeType: string, data: string}  $photo
     * @return array{matches: bool, differences: string, split: bool, input_tokens: int, output_tokens: int}
     */
    public function matchesProduct(string $imageBytes, string $mimeType, string $product, array $photo): array
    {
        $prompt = "The first image is an advertising picture. The second image is the store's own photo of the product \"{$product}\". "
            .'In the second image, books, magazines, phones, tablets, e-readers, plants, cups, hands and any other items on, in or around the product are props, not the product. '
            .'Does the first image show the same product: the same kind of object, shape, proportions, material, surface finish and colour? '
            .'A different kind of object does not match, even if it has a similar outline: for example a real paper book with pages is not a sculpted or moulded stand shaped like a book. '
            .'Ignore the background, lighting, camera angle and props in both images. '
            .'Separately, set split to true if the first image is divided into panels: a flat colour block, a split-screen, or a hard straight vertical or horizontal boundary between two areas that are not the same continuous scene. Return JSON only.';
        [$mimeType, $imageBytes] = $this->smaller($mimeType, $imageBytes);
        [$response] = $this->client->generateContent([
            'contents' => [['role' => 'user', 'parts' => [
                ['text' => $prompt],
                ['inlineData' => ['mimeType' => $mimeType, 'data' => base64_encode($imageBytes)]],
                ['inlineData' => $photo],
            ]]],
            'generationConfig' => [
                'temperature' => 0,
                'responseMimeType' => 'application/json',
                'responseSchema' => [
                    'type' => 'OBJECT',
                    'properties' => ['matches' => ['type' => 'BOOLEAN'], 'differences' => ['type' => 'STRING'], 'split' => ['type' => 'BOOLEAN']],
                    'required' => ['matches', 'differences', 'split'],
                ],
            ],
        ], config('campaigns.google.model'), (int) config('campaigns.banners.inspection_timeout_seconds', 30));

        $json = $response->json();
        $decoded = json_decode((string) data_get($json, 'candidates.0.content.parts.0.text'), true);
        if (! is_array($decoded) || ! is_bool($decoded['matches'] ?? null)) {
            throw new VertexAIResponseException('vertex_invalid_inspection', 'Vertex AI returned no product match check.', true);
        }

        return [
            'matches' => $decoded['matches'],
            'differences' => trim((string) ($decoded['differences'] ?? '')),
            'split' => ($decoded['split'] ?? false) === true,
            'input_tokens' => (int) data_get($json, 'usageMetadata.promptTokenCount', 0),
            'output_tokens' => (int) data_get($json, 'usageMetadata.candidatesTokenCount', 0),
        ];
    }

    /**
     * A 1K PNG is 1-2MB; reading text needs far less, and smaller bodies answer faster.
     *
     * @return array{0: string, 1: string}
     */
    private function smaller(string $mimeType, string $bytes): array
    {
        $image = @imagecreatefromstring($bytes);
        if (! $image) {
            return [$mimeType, $bytes];
        }
        $width = imagesx($image);
        if ($width > 768) {
            $scaled = imagescale($image, 768);
            imagedestroy($image);
            $image = $scaled;
        }
        ob_start();
        imagejpeg($image, null, 85);
        imagedestroy($image);

        return ['image/jpeg', (string) ob_get_clean()];
    }
}
