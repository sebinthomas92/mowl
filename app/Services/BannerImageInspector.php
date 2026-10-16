<?php

namespace App\Services;

use App\Exceptions\VertexAIResponseException;
use Illuminate\Http\Client\Response;

/**
 * Looks at a generated banner background for readable third-party text: book covers on a screen,
 * magazine spines, labels, logos. The image prompt asks for none, but the model copies what the
 * product reference shows, so the prompt alone does not hold.
 */
class BannerImageInspector
{
    private static bool $thinkingRejected = false;

    public function __construct(private GoogleVertexAIClient $client) {}

    /**
     * Only the generated image is sent. When the product photo was sent alongside it, the model listed
     * the photo's own props (the book, Kindle and magazine on the Kindle Stand page) as if they were in
     * the background, so every round of pack 20 was rejected.
     *
     * With $sceneOnly, the image is a scene the real product is composited onto afterwards, so it is
     * also asked whether the product (or something like it) was drawn anyway.
     *
     * With $cutout, the image is a product cut out of a store photo, so it is also asked to name any
     * object that is not the product (a vase or candle beside it in the photo).
     *
     * @return array{marks: list<string>, product_shown: bool, other_objects: list<string>, input_tokens: int, output_tokens: int}
     */
    public function inspect(string $imageBytes, string $mimeType, string $product, string $brand, bool $sceneOnly = false, bool $cutout = false): array
    {
        $prompt = "This is an advertising background for the product \"{$product}\" by the brand \"{$brand}\". "
            .'List every piece of readable text or recognisable mark in this image: book or e-book covers and titles, '
            .'author names, magazine or newspaper names, book spines, labels and packaging of other items, logos and other brands\' names, '
            .'and anything shown on a device screen (a phone, tablet or e-reader). '
            .'Do not list the brand or product name above where it is printed on the product itself. '
            .'Do not list blank, blurred or illegible shapes. Only report what is visible in this image. '
            .($sceneOnly ? "Also set product_shown to true if the image shows {$product} or any object like it. " : '')
            .($cutout ? "Also list in other_objects every object in this image that is not part of {$product} itself, such as a vase, candle, plant, cup, book or device; leave it empty if only {$product} is shown. " : '')
            .'Return JSON only.';
        [$mimeType, $imageBytes] = $this->smaller($mimeType, $imageBytes);
        $properties = ['marks' => ['type' => 'ARRAY', 'items' => ['type' => 'STRING']]];
        if ($sceneOnly) {
            $properties['product_shown'] = ['type' => 'BOOLEAN'];
        }
        if ($cutout) {
            $properties['other_objects'] = ['type' => 'ARRAY', 'items' => ['type' => 'STRING']];
        }
        $parts = [
            ['text' => $prompt],
            ['inlineData' => ['mimeType' => $mimeType, 'data' => base64_encode($imageBytes)]],
        ];
        $response = $this->ask([
            'contents' => [['role' => 'user', 'parts' => $parts]],
            'generationConfig' => [
                'temperature' => 0,
                'responseMimeType' => 'application/json',
                'responseSchema' => [
                    'type' => 'OBJECT',
                    'properties' => $properties,
                    'required' => array_keys($properties),
                ],
            ],
        ]);

        $json = $response->json();
        $decoded = json_decode((string) data_get($json, 'candidates.0.content.parts.0.text'), true);
        if (! is_array($decoded) || ! is_array($decoded['marks'] ?? null) || ($sceneOnly && ! is_bool($decoded['product_shown'] ?? null)) || ($cutout && ! is_array($decoded['other_objects'] ?? null))) {
            throw new VertexAIResponseException('vertex_invalid_inspection', 'Vertex AI returned no banner image inspection.', true);
        }

        return [
            'marks' => array_values(array_filter(array_map(fn ($mark) => is_string($mark) ? trim($mark) : '', $decoded['marks']))),
            'product_shown' => $sceneOnly && $decoded['product_shown'],
            'other_objects' => $cutout ? array_values(array_filter(array_map(fn ($object) => is_string($object) ? trim($object) : '', $decoded['other_objects']))) : [],
            'input_tokens' => (int) data_get($json, 'usageMetadata.promptTokenCount', 0),
            // Thinking tokens are billed as output too.
            'output_tokens' => (int) data_get($json, 'usageMetadata.candidatesTokenCount', 0) + (int) data_get($json, 'usageMetadata.thoughtsTokenCount', 0),
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
            .'In the second image, any item on, in or around the product (a book, magazine, phone, tablet, e-reader, plant, cup or hand) is a prop, not the product, unless that item is itself the named product. '
            .'Does the first image show the same product: the same kind of object, shape, proportions, material, surface finish and the colour of every part (a base, cover, frame or trim in a different colour does not match)? '
            .'A different kind of object does not match, even if it has a similar outline: for example a real paper book with pages is not a sculpted or moulded stand shaped like a book. '
            .'Ignore the background, lighting, camera angle and props in both images. '
            .'Separately, set split to true if the first image is divided into panels: a flat colour block, a split-screen, or a hard straight vertical or horizontal boundary between two areas that are not the same continuous scene. Return JSON only.';
        [$mimeType, $imageBytes] = $this->smaller($mimeType, $imageBytes);
        // A store's full-size photo can exceed the model's inline image limit, so it is shrunk too.
        [$photoMime, $photoBytes] = $this->smaller((string) $photo['mimeType'], (string) base64_decode((string) $photo['data']));
        $response = $this->ask([
            'contents' => [['role' => 'user', 'parts' => [
                ['text' => $prompt],
                ['inlineData' => ['mimeType' => $mimeType, 'data' => base64_encode($imageBytes)]],
                ['inlineData' => ['mimeType' => $photoMime, 'data' => base64_encode($photoBytes)]],
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
        ]);

        $json = $response->json();
        $decoded = json_decode((string) data_get($json, 'candidates.0.content.parts.0.text'), true);
        if (! is_array($decoded) || ! is_bool($decoded['matches'] ?? null) || ! is_bool($decoded['split'] ?? null)) {
            throw new VertexAIResponseException('vertex_invalid_inspection', 'Vertex AI returned no product match check.', true);
        }

        return [
            'matches' => $decoded['matches'],
            'differences' => trim((string) ($decoded['differences'] ?? '')),
            'split' => $decoded['split'],
            'input_tokens' => (int) data_get($json, 'usageMetadata.promptTokenCount', 0),
            // Thinking tokens are billed as output too.
            'output_tokens' => (int) data_get($json, 'usageMetadata.candidatesTokenCount', 0) + (int) data_get($json, 'usageMetadata.thoughtsTokenCount', 0),
        ];
    }

    /**
     * A yes/no look at an image needs little reasoning, and long default thinking can run past the
     * inspection timeout with nothing sent back. A model that rejects the setting is asked without it.
     */
    private function ask(array $payload): Response
    {
        $level = (string) config('campaigns.banners.inspection_thinking_level');
        if ($level !== '' && ! self::$thinkingRejected) {
            $payload['generationConfig']['thinkingConfig'] = ['thinkingLevel' => $level];
        }
        try {
            [$response] = $this->client->generateContent($payload, config('campaigns.google.model'), (int) config('campaigns.banners.inspection_timeout_seconds', 30));
        } catch (VertexAIResponseException $exception) {
            if (! isset($payload['generationConfig']['thinkingConfig']) || $exception->errorCode !== 'vertex_http_400' || ! str_contains(strtolower($exception->getMessage()), 'thinking')) {
                throw $exception;
            }
            self::$thinkingRejected = true;
            unset($payload['generationConfig']['thinkingConfig']);
            [$response] = $this->client->generateContent($payload, config('campaigns.google.model'), (int) config('campaigns.banners.inspection_timeout_seconds', 30));
        }

        return $response;
    }

    /**
     * A 1K PNG is 1-2MB; reading text needs far less, and smaller bodies answer faster.
     *
     * @return array{0: string, 1: string}
     */
    private function smaller(string $mimeType, string $bytes): array
    {
        $size = @getimagesizefromstring($bytes);
        // Decoding a larger photo would not fit in memory; callers keep such photos out of checks.
        if (! $size || $size[0] * $size[1] > ProductImageImporter::MAX_PIXELS) {
            return [$mimeType, $bytes];
        }
        $image = @imagecreatefromstring($bytes);
        if (! $image) {
            return [$mimeType, $bytes];
        }
        // An indexed PNG's pixels are palette indexes, not colours with their alpha.
        imagepalettetotruecolor($image);
        // Bounded by the longer side: a tall, narrow store photo is as large as a wide one.
        $factor = 768 / max(imagesx($image), imagesy($image));
        if ($factor < 1) {
            $scaled = imagescale($image, max(1, (int) round(imagesx($image) * $factor)), max(1, (int) round(imagesy($image) * $factor)));
            imagedestroy($image);
            $image = $scaled;
        }
        $image = $this->flattened($image);
        ob_start();
        imagejpeg($image, null, 85);
        imagedestroy($image);

        return ['image/jpeg', (string) ob_get_clean()];
    }

    /**
     * A transparent cutout on a backdrop that contrasts with the product: JPEG has no alpha, and the
     * clear pixels' own RGB (black) would hide a dark product or prop.
     */
    private function flattened(\GdImage $image): \GdImage
    {
        $width = imagesx($image);
        $height = imagesy($image);
        $step = max(1, intdiv(max($width, $height), 120));
        $light = 0.0;
        $opaque = 0;
        $clear = 0;
        for ($y = 0; $y < $height; $y += $step) {
            for ($x = 0; $x < $width; $x += $step) {
                $colour = imagecolorat($image, $x, $y);
                if ((($colour >> 24) & 127) >= 64) {
                    $clear++;

                    continue;
                }
                $opaque++;
                $light += ((($colour >> 16) & 255) + (($colour >> 8) & 255) + ($colour & 255)) / 3;
            }
        }
        if ($clear === 0) {
            return $image;
        }
        $grey = $opaque > 0 && $light / $opaque > 140 ? 45 : 235;
        $flat = imagecreatetruecolor($width, $height);
        imagefill($flat, 0, 0, imagecolorallocate($flat, $grey, $grey, $grey));
        imagealphablending($flat, true);
        imagecopy($flat, $image, 0, 0, 0, 0, $width, $height);
        imagedestroy($image);

        return $flat;
    }
}
