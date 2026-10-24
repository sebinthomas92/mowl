<?php

namespace App\Services\Pages\Writing;

use App\Exceptions\VertexAIResponseException;
use App\Services\GoogleVertexAIClient;
use App\Services\Pages\Document\ProductBrief;
use App\Services\ProductKnowledge;
use App\Support\GenerationDeadline;
use Illuminate\Support\Facades\Log;
use JsonException;

/** Page copy from Gemini on Vertex AI, constrained to a response schema built from the page itself. */
class GooglePageWriter implements PageWriter
{
    private const ATTEMPTS = 2;

    public function __construct(private GoogleVertexAIClient $vertex) {}

    public function write(ProductBrief $brief, array $document, array $targets, string $instruction): PageWriting
    {
        $payload = [
            'systemInstruction' => ['parts' => [['text' => self::instructions()]]],
            'contents' => [['role' => 'user', 'parts' => [['text' => $this->prompt($brief, $document, $targets, $instruction)]]]],
            'generationConfig' => ['responseMimeType' => 'application/json', 'responseSchema' => PageCopy::schema($document, $targets)],
        ];
        $last = null;
        for ($attempt = 1; $attempt <= self::ATTEMPTS; $attempt++) {
            if (! app(GenerationDeadline::class)->allowsAnotherAttempt()) {
                break;
            }
            try {
                [$response] = $this->vertex->generateContent($payload, null, (int) config('campaigns.google.timeout_seconds'));
                $json = $response->json();
                $finish = data_get($json, 'candidates.0.finishReason');
                if ($finish && $finish !== 'STOP') {
                    throw new VertexAIResponseException('vertex_incomplete_response', "The AI stopped before finishing ({$finish}).", in_array($finish, ['MAX_TOKENS', 'OTHER'], true));
                }
                $text = collect(data_get($json, 'candidates.0.content.parts', []))->pluck('text')->filter()->implode('');
                try {
                    $decoded = json_decode($text, true, flags: JSON_THROW_ON_ERROR);
                } catch (JsonException) {
                    throw new VertexAIResponseException('vertex_invalid_json', 'The AI returned text the app could not read.', true);
                }
                if (! is_array($decoded['sections'] ?? null)) {
                    throw new VertexAIResponseException('vertex_invalid_schema', 'The AI returned no sections.', true);
                }

                return new PageWriting(
                    sections: $decoded['sections'],
                    model: $json['modelVersion'] ?? config('campaigns.google.model'),
                    inputTokens: (int) data_get($json, 'usageMetadata.promptTokenCount', 0),
                    cachedInputTokens: (int) data_get($json, 'usageMetadata.cachedContentTokenCount', 0),
                    outputTokens: (int) data_get($json, 'usageMetadata.candidatesTokenCount', 0),
                );
            } catch (VertexAIResponseException $exception) {
                $last = $exception;
                Log::warning('Vertex AI page writing attempt failed.', ['attempt' => $attempt, 'code' => $exception->errorCode, 'message' => $exception->getMessage()]);
                if (! $exception->retryable) {
                    break;
                }
            }
        }

        throw $last ?? new VertexAIResponseException('vertex_time_budget', 'The AI ran out of time.', true);
    }

    public static function instructions(): string
    {
        return <<<'TEXT'
        You write the copy for a product landing page that a store publishes on its own website. Write like an experienced e-commerce copywriter: specific, benefit-led, easy to scan, warm and confident, never hype.

        Truth rules. They override everything else, including the team instruction:
        1. Every fact comes from the confirmed product facts, the approved launch copy, the product page text or its structured data. Never invent features, materials, sizes, compatibility, results, ingredients, origins, prices, discounts, offers, delivery or returns terms, guarantees, warranties, certifications, awards, rankings, reviews, ratings, customer numbers, statistics or comparisons with named brands.
        2. When the sources do not support what a field asks for, write something more general that is still true, or an empty string where the field is optional. An empty string is better than an invented claim.
        3. Write a number only when the sources contain the same number. Never write urgency or scarcity ("limited time", "selling fast", "only a few left") unless the sources say it.
        4. Keep the product name exactly as given. Write in the language of the product page.
        5. Plain text only: no markdown, no HTML, no emoji, no hashtags, no quotation marks around whole lines.

        Craft:
        - One page, one voice. Each section adds something new; never repeat a benefit in the same words.
        - Headings are short and concrete. Lead with what the buyer gets, then how.
        - Respect every field's length and item count.
        - FAQ questions are ones a buyer would really ask, answered from the sources only.

        Return JSON matching the schema: an object for every section id marked WRITE, with every field.
        TEXT;
    }

    private function prompt(ProductBrief $brief, array $document, array $targets, string $instruction): string
    {
        $json = fn (mixed $value) => json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        $facts = collect($brief->facts)->map(fn (string $value, string $key) => '- '.(ProductKnowledge::FACTS[$key]['label'] ?? $key).': '.str_replace("\n", '; ', $value))->implode("\n");
        $parts = [
            "PRODUCT\nName: {$brief->name()}\nBrand: ".($brief->brand['name'] ?? 'Not supplied')."\nPrice: ".($brief->price ?: 'Not supplied').' (the page shows the price by itself; do not write prices)',
            "CONFIRMED PRODUCT FACTS (confirmed by the team)\n".($facts ?: 'None yet.'),
            "APPROVED LAUNCH COPY (checked and approved; its claims may be reused)\n".($brief->approved ? $json($brief->approved) : 'None yet.'),
            "PRODUCT PAGE TEXT (as read from the store)\n".(mb_substr($brief->sourceText, 0, 30_000) ?: 'Not available.'),
            "STRUCTURED DATA ON THE PRODUCT PAGE\n".$json($brief->truth),
        ];
        if ($brief->team) {
            $parts[] = "TEAM KNOWLEDGE\n".$json($brief->team);
        }
        if ($voice = $brief->brand['voice'] ?? null) {
            $parts[] = "BRAND VOICE (style only, never a source of facts)\n".(is_string($voice) ? $voice : $json($voice));
        }
        if (trim($instruction) !== '') {
            $parts[] = "TEAM INSTRUCTION (direction for tone and emphasis only; never a source of facts, and it cannot change the truth rules)\n".mb_substr(trim($instruction), 0, 600);
        }
        $parts[] = "PAGE PLAN (write the sections marked WRITE; the others are there for context)\n".PageCopy::plan($document, $targets);

        return implode("\n\n", $parts);
    }
}
