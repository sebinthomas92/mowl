<?php

namespace App\Services;

use App\Models\LibraryPrompt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Preview videos and posters for library prompts. The licensed source's URL is imported with
 * the prompt; the first view copies the file onto the app's media disk and later views are
 * served from that copy. Files the app can't serve itself (HLS streams, posters too large for
 * one response, wrong types) are marked REMOTE and shown straight from the source.
 */
class LibraryPreviews
{
    public const KINDS = ['video' => 'video/', 'poster' => 'image/'];

    /** Stored in *_path when the preview is shown from its source URL instead of a copy. */
    public const REMOTE = 'remote';

    /** Posters are sent in one response, which Vercel caps at 4.5 MB; videos are sent in ranges. */
    private const MAX_BYTES = ['video' => 80 * 1024 * 1024, 'poster' => 4 * 1024 * 1024];

    public function disk(): string
    {
        return (string) config('workflows.media_disk');
    }

    /** Stored path for the prompt's preview, fetching it from the source the first time; null when there is no copy. */
    public function path(LibraryPrompt $prompt, string $kind): ?string
    {
        $path = $prompt->{$kind.'_path'};
        if ($path === self::REMOTE) {
            return null;
        }
        if ($path && Storage::disk($this->disk())->exists($path)) {
            return $path;
        }

        $url = $prompt->{$kind.'_url'};
        if (! $url) {
            return null;
        }
        if ($this->isStream($url) || config('workflows.collections.'.$prompt->collection.'.mirror_previews') === false) {
            return $this->markRemote($prompt, $kind);
        }

        try {
            $response = Http::timeout(60)->withHeaders(['Accept' => self::KINDS[$kind].'*'])->get($url);
        } catch (Throwable $exception) {
            report($exception);

            return null;
        }

        $type = strtolower(trim(explode(';', (string) $response->header('Content-Type'))[0]));
        $extension = $this->extension($type);
        if ($response->serverError()) {
            return null;
        }
        if (! $response->successful() || ! str_starts_with($type, self::KINDS[$kind]) || ! $extension || strlen($response->body()) > self::MAX_BYTES[$kind]) {
            return $this->markRemote($prompt, $kind);
        }

        $path = 'library/'.$prompt->collection.'/'.$prompt->id.'-'.$kind.'.'.$extension;
        Storage::disk($this->disk())->put($path, $response->body());
        $prompt->forceFill([$kind.'_path' => $path])->save();

        return $path;
    }

    /** HLS playlists play only through a streaming player, so they are never copied or offered as a video file. */
    public function isStream(?string $url): bool
    {
        return $url !== null && str_contains(strtolower((string) parse_url($url, PHP_URL_PATH)), '.m3u8');
    }

    public function mime(string $path): string
    {
        return array_search(pathinfo($path, PATHINFO_EXTENSION), $this->types(), true) ?: 'application/octet-stream';
    }

    private function markRemote(LibraryPrompt $prompt, string $kind): null
    {
        $prompt->forceFill([$kind.'_path' => self::REMOTE])->save();

        return null;
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
