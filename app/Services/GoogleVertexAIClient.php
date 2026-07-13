<?php

namespace App\Services;

use App\Exceptions\VertexAIResponseException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class GoogleVertexAIClient
{
    public function __construct(private GoogleCloudAccessTokenProvider $tokens) {}

    public function generateContent(array $payload, ?string $model = null): array
    {
        $model ??= config('campaigns.google.model');
        $startedAt = hrtime(true);

        try {
            $response = Http::withToken($this->tokens->fetchAuthToken()['access_token'])
                ->acceptJson()
                ->timeout(config('campaigns.google.timeout_seconds'))
                ->post($this->endpoint($model), $payload);
        } catch (ConnectionException $exception) {
            throw new VertexAIResponseException('vertex_connection_error', 'Vertex AI connection failed: '.$exception->getMessage(), true);
        }

        if ($response->failed()) {
            throw $this->responseException($response);
        }

        return [$response, (int) round((hrtime(true) - $startedAt) / 1_000_000)];
    }

    public function transcribeAudio(string $path): string
    {
        $lastException = null;

        for ($attempt = 1; $attempt <= config('campaigns.google.retry_attempts'); $attempt++) {
            try {
                [$response] = $this->generateContent([
                    'contents' => [[
                        'role' => 'user',
                        'parts' => [
                            ['text' => 'Transcribe this product video audio accurately. Return only the transcript text.'],
                            ['inlineData' => ['mimeType' => 'audio/wav', 'data' => base64_encode(file_get_contents($path))]],
                        ],
                    ]],
                    'generationConfig' => ['temperature' => 0],
                ]);

                $text = data_get($response->json(), 'candidates.0.content.parts.0.text');
                if (! is_string($text) || trim($text) === '') {
                    throw new VertexAIResponseException('vertex_missing_transcript', 'Vertex AI returned no audio transcript.', true);
                }

                return trim($text);
            } catch (VertexAIResponseException $exception) {
                $lastException = $exception;
                if (! $exception->retryable || $attempt === config('campaigns.google.retry_attempts')) {
                    throw $exception;
                }
                usleep($this->retryDelay($attempt) * 1000);
            }
        }

        throw $lastException;
    }

    private function endpoint(string $model): string
    {
        $project = config('campaigns.google.project');
        $location = config('campaigns.google.location');
        if (! $project || ! $location || ! $model) {
            throw new VertexAIResponseException('vertex_not_configured', 'Google Vertex AI project, location, and model must be configured.');
        }

        return rtrim(config('campaigns.google.base_url'), '/')
            ."/projects/{$project}/locations/{$location}/publishers/google/models/{$model}:generateContent";
    }

    private function responseException(Response $response): VertexAIResponseException
    {
        $status = $response->status();
        $message = data_get($response->json(), 'error.message', "Vertex AI returned HTTP {$status}.");

        return new VertexAIResponseException(
            "vertex_http_{$status}",
            "Vertex AI request failed ({$status}): {$message}",
            $status === 408 || $status === 409 || $status === 429 || $status >= 500,
        );
    }

    private function retryDelay(int $attempt): int
    {
        return config('campaigns.google.retry_backoff_ms')[$attempt - 1] ?? 0;
    }
}
