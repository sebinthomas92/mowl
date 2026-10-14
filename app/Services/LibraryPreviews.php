<?php

namespace App\Services;

use App\Models\LibraryPrompt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Preview videos and posters for library prompts. The licensed source's URL is imported with
 * the prompt; the first view copies the file onto the app's media disk and later views are
 * served from that copy.
 */
class LibraryPreviews
{
    public const KINDS = ['video' => 'video/', 'poster' => 'image/'];

    private const MAX_BYTES = 80 * 1024 * 1024;

    public function disk(): string
    {
        return (string) config('workflows.media_disk');
    }

    /** Stored path for the prompt's preview, fetching it from the source the first time. */
    public function path(LibraryPrompt $prompt, string $kind): ?string
    {
        $path = $prompt->{$kind.'_path'};
        if ($path && Storage::disk($this->disk())->exists($path)) {
            return $path;
        }

        $url = $prompt->{$kind.'_url'};
        if (! $url) {
            return null;
        }

        try {
            $response = Http::timeout(60)->withHeaders(['Accept' => self::KINDS[$kind].'*'])->get($url);
        } catch (Throwable $exception) {
            report($exception);

            return null;
        }

        $type = strtolower(trim(explode(';', (string) $response->header('Content-Type'))[0]));
        $extension = $this->extension($type);
        if (! $response->successful() || ! str_starts_with($type, self::KINDS[$kind]) || ! $extension || strlen($response->body()) > self::MAX_BYTES) {
            return null;
        }

        $path = 'library/'.$prompt->collection.'/'.$prompt->id.'-'.$kind.'.'.$extension;
        Storage::disk($this->disk())->put($path, $response->body());
        $prompt->forceFill([$kind.'_path' => $path])->save();

        return $path;
    }

    public function mime(string $path): string
    {
        return array_search(pathinfo($path, PATHINFO_EXTENSION), $this->types(), true) ?: 'application/octet-stream';
    }

    private function extension(string $type): ?string
    {
        return $this->types()[$type] ?? null;
    }

    /** @return array<string, string> */
    private function types(): array
    {
        return ['video/mp4' => 'mp4', 'video/webm' => 'webm', 'video/quicktime' => 'mov', 'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif', 'image/avif' => 'avif'];
    }
}
