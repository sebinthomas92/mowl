<?php

namespace App\Http\Controllers;

use App\Models\LibraryPrompt;
use App\Services\LibraryPreviews;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Throwable;

class WorkflowPreviewController extends Controller
{
    /** Largest body sent per response; players fetch the rest with further range requests. */
    private const CHUNK = 3 * 1024 * 1024;

    public function __invoke(Request $request, LibraryPrompt $prompt, string $kind, LibraryPreviews $previews)
    {
        try {
            return $this->serve($request, $prompt, $kind, $previews);
        } catch (Throwable $exception) {
            // A storage or source failure must never break the page; show the preview from its source instead.
            report($exception);

            return $this->fromSource($prompt, $kind);
        }
    }

    private function serve(Request $request, LibraryPrompt $prompt, string $kind, LibraryPreviews $previews)
    {
        $path = $previews->path($prompt, $kind);
        if ($path === null) {
            return $this->fromSource($prompt, $kind);
        }

        $disk = Storage::disk($previews->disk());
        $size = $disk->size($path);
        // Images are sent whole (they are stored only when small enough); videos in ranges.
        [$start, $end] = $this->range($kind === 'video' ? (string) $request->header('Range') : '', $size, $kind === 'video' ? self::CHUNK : $size);
        if ($start === null) {
            return response('', 416, ['Content-Range' => "bytes */{$size}"]);
        }
        $partial = $start > 0 || $end < $size - 1;

        $stream = $disk->readStream($path);
        abort_unless(is_resource($stream), 404);

        return response()->stream(function () use ($stream, $start, $end): void {
            // Storage streams may not be seekable, so skip ahead by reading.
            if ($start > 0 && @fseek($stream, $start) !== 0) {
                for ($skip = $start; $skip > 0 && ! feof($stream);) {
                    $skip -= strlen((string) fread($stream, min(1048576, $skip)));
                }
            }
            for ($left = $end - $start + 1; $left > 0 && ! feof($stream);) {
                $chunk = (string) fread($stream, min(1048576, $left));
                $left -= strlen($chunk);
                echo $chunk;
            }
            fclose($stream);
        }, $partial ? 206 : 200, array_filter([
            'Content-Type' => $previews->mime($path),
            'Content-Length' => (string) ($end - $start + 1),
            'Content-Range' => $partial ? "bytes {$start}-{$end}/{$size}" : null,
            'Accept-Ranges' => 'bytes',
            'Cache-Control' => 'private, max-age=86400',
            'X-Content-Type-Options' => 'nosniff',
        ]));
    }

    private function fromSource(LibraryPrompt $prompt, string $kind)
    {
        $url = LibraryPreviews::safeUrl($prompt->{$kind.'_url'});
        abort_unless($url && $kind === 'poster', 404);

        return redirect()->away($url)->header('Cache-Control', 'private, max-age=86400');
    }

    /** @return array{0: ?int, 1: int} */
    private function range(string $header, int $size, int $chunk): array
    {
        $start = 0;
        $end = $size - 1;
        if (preg_match('/^bytes=(\d*)-(\d*)$/', trim($header), $match) && ($match[1] !== '' || $match[2] !== '')) {
            if ($match[1] === '') {
                $start = max(0, $size - (int) $match[2]);
            } else {
                $start = (int) $match[1];
                $end = $match[2] === '' ? $end : min($end, (int) $match[2]);
            }
            if ($start > $end) {
                return [null, $end];
            }
        }

        return [$start, min($end, $start + max(1, $chunk) - 1)];
    }
}
