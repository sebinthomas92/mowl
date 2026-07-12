<?php

namespace App\Services;

use App\Models\MediaAsset;
use App\Models\Product;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;

class MediaProcessor
{
    public function processForProduct(Product $product): array
    {
        $summary = ['frames' => [], 'images' => [], 'transcripts' => [], 'assets' => []];

        foreach ($product->mediaAssets()->get() as $asset) {
            $summary['assets'][] = [
                'id' => $asset->id,
                'type' => $asset->type,
                'content_hash' => $asset->content_hash,
                'status' => $asset->status,
                'metadata' => $asset->metadata,
            ];
            foreach ($asset->derivatives['frames'] ?? [] as $frame) {
                $summary['frames'][] = $frame + ['asset_id' => $asset->id];
            }
            foreach ($asset->derivatives['images'] ?? [] as $image) {
                $summary['images'][] = $image + ['asset_id' => $asset->id];
            }
            if ($transcript = data_get($asset->metadata, 'transcript')) {
                $summary['transcripts'][] = ['asset_id' => $asset->id, 'text' => $transcript];
            }
        }

        $summary['frames'] = array_slice($summary['frames'], 0, config('campaigns.media.max_frames'));

        return $summary;
    }

    public function process(MediaAsset $asset): void
    {
        if ($asset->status === 'processed') {
            return;
        }

        $startedAt = hrtime(true);
        $asset->update([
            'status' => 'processing',
            'error_message' => null,
            'processing_attempts' => DB::raw('processing_attempts + 1'),
            'processing_started_at' => now(),
        ]);
        $temporaryDirectory = sys_get_temp_dir().'/marketing-owl-processing/'.Str::uuid();
        File::ensureDirectoryExists($temporaryDirectory);
        $temporarySource = null;

        try {
            [$sourcePath, $temporarySource] = $this->materialize($asset, $temporaryDirectory);
            $processed = $asset->type === 'video'
                ? $this->processVideo($asset, $sourcePath, $temporaryDirectory)
                : $this->processImage($asset, $sourcePath, $temporaryDirectory);

            $asset->update([
                'status' => 'processed',
                'derivatives' => $processed['derivatives'],
                'metadata' => $processed['metadata'],
                'processed_at' => now(),
                'processing_duration_ms' => (int) ((hrtime(true) - $startedAt) / 1_000_000),
                'processing_cost' => 0,
            ]);
        } catch (Throwable $exception) {
            $asset->update([
                'status' => 'failed',
                'error_message' => mb_substr($exception->getMessage(), 0, 2000),
                'processing_duration_ms' => (int) ((hrtime(true) - $startedAt) / 1_000_000),
            ]);
            throw $exception;
        } finally {
            File::deleteDirectory($temporaryDirectory);
            if ($temporarySource && is_file($temporarySource)) {
                @unlink($temporarySource);
            }
        }
    }

    private function processVideo(MediaAsset $asset, string $sourcePath, string $temporaryDirectory): array
    {
        $probe = $this->run([
            config('campaigns.media.ffprobe'), '-v', 'error', '-print_format', 'json', '-show_streams', '-show_format', $sourcePath,
        ]);
        $probeData = json_decode($probe, true, flags: JSON_THROW_ON_ERROR);
        $duration = (float) data_get($probeData, 'format.duration', 0);
        if ($duration <= 0 || $duration > config('campaigns.media.max_video_seconds')) {
            throw new RuntimeException('Videos must be readable and no longer than '.config('campaigns.media.max_video_seconds').' seconds.');
        }

        $framePattern = $temporaryDirectory.'/frame-%03d.jpg';
        $sceneThreshold = config('campaigns.media.scene_threshold');
        $this->run([
            config('campaigns.media.ffmpeg'), '-hide_banner', '-loglevel', 'error', '-i', $sourcePath,
            '-vf', "select=gt(scene\\,{$sceneThreshold}),scale=512:-2:force_original_aspect_ratio=decrease,format=yuvj420p",
            '-fps_mode', 'vfr', '-frames:v', '32', '-q:v', '3', $framePattern,
        ]);
        $candidates = File::glob($temporaryDirectory.'/frame-*.jpg');

        if (count($candidates) < config('campaigns.media.min_frames')) {
            File::delete($candidates);
            $fps = max(0.1, config('campaigns.media.min_frames') / $duration);
            $this->run([
                config('campaigns.media.ffmpeg'), '-hide_banner', '-loglevel', 'error', '-i', $sourcePath,
                '-vf', "fps={$fps},scale=512:-2:force_original_aspect_ratio=decrease,format=yuvj420p",
                '-frames:v', '24', '-q:v', '3', $framePattern,
            ]);
            $candidates = File::glob($temporaryDirectory.'/frame-*.jpg');
        }

        $selected = $this->deduplicateFrames($candidates);
        $storedFrames = [];
        foreach ($selected as $index => $frame) {
            $path = "campaign-media/{$asset->workspace_id}/{$asset->product_id}/derived/{$asset->content_hash}/frame-".str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT).'.jpg';
            Storage::disk($asset->disk)->put($path, file_get_contents($frame));
            $storedFrames[] = [
                'disk' => $asset->disk,
                'path' => $path,
                'content_hash' => hash_file('sha256', $frame),
                'perceptual_hash' => $this->differenceHash($frame),
                'width' => 512,
            ];
        }

        $audio = null;
        $transcript = null;
        $transcriptionStatus = 'not_available';
        $hasAudio = collect($probeData['streams'] ?? [])->contains(fn (array $stream) => ($stream['codec_type'] ?? null) === 'audio');
        if ($hasAudio) {
            $audioFile = $temporaryDirectory.'/audio.wav';
            $this->run([
                config('campaigns.media.ffmpeg'), '-hide_banner', '-loglevel', 'error', '-i', $sourcePath,
                '-vn', '-ac', '1', '-ar', '16000', '-c:a', 'pcm_s16le', $audioFile,
            ]);
            $audio = "campaign-media/{$asset->workspace_id}/{$asset->product_id}/derived/{$asset->content_hash}/audio.wav";
            Storage::disk($asset->disk)->put($audio, file_get_contents($audioFile));
            [$transcript, $transcriptionStatus] = $this->transcribeIfConfigured($audioFile);
        }

        return [
            'derivatives' => ['frames' => $storedFrames, 'audio' => $audio],
            'metadata' => [
                'duration_seconds' => round($duration, 3),
                'frame_candidates' => count($candidates),
                'frames_selected' => count($storedFrames),
                'frame_target_met' => count($storedFrames) >= config('campaigns.media.min_frames'),
                'transcript' => $transcript,
                'transcription_status' => $transcriptionStatus,
            ],
        ];
    }

    private function processImage(MediaAsset $asset, string $sourcePath, string $temporaryDirectory): array
    {
        $output = $temporaryDirectory.'/image.jpg';
        $this->run([
            config('campaigns.media.ffmpeg'), '-hide_banner', '-loglevel', 'error', '-i', $sourcePath,
            '-vf', 'scale=512:-2:force_original_aspect_ratio=decrease,format=yuvj420p', '-frames:v', '1', '-q:v', '3', $output,
        ]);
        $path = "campaign-media/{$asset->workspace_id}/{$asset->product_id}/derived/{$asset->content_hash}/image.jpg";
        Storage::disk($asset->disk)->put($path, file_get_contents($output));

        return [
            'derivatives' => ['images' => [[
                'disk' => $asset->disk,
                'path' => $path,
                'content_hash' => hash_file('sha256', $output),
                'width' => 512,
            ]]],
            'metadata' => [],
        ];
    }

    private function transcribeIfConfigured(string $audioFile): array
    {
        $apiKey = config('services.openai.api_key');
        if (! $apiKey) {
            return [null, 'pending_credentials'];
        }

        $handle = fopen($audioFile, 'r');
        $response = Http::withToken($apiKey)
            ->timeout(120)
            ->attach('file', $handle, 'audio.wav')
            ->post(rtrim(config('campaigns.openai.base_url'), '/').'/audio/transcriptions', [
                'model' => config('campaigns.media.transcription_model'),
                'response_format' => 'json',
            ])
            ->throw();

        if (is_resource($handle)) {
            fclose($handle);
        }

        return [$response->json('text'), 'completed'];
    }

    private function deduplicateFrames(array $frames): array
    {
        sort($frames);
        $selected = [];
        $hashes = [];
        foreach ($frames as $frame) {
            $hash = $this->differenceHash($frame);
            $unique = collect($hashes)->every(fn (string $existing) => $this->hammingDistance($hash, $existing) > config('campaigns.media.dedupe_hamming_distance'));
            if ($unique) {
                $selected[] = $frame;
                $hashes[] = $hash;
            }
            if (count($selected) >= config('campaigns.media.max_frames')) {
                break;
            }
        }

        return $selected;
    }

    private function differenceHash(string $path): string
    {
        $source = imagecreatefromjpeg($path);
        if (! $source) {
            throw new RuntimeException('A generated frame could not be decoded.');
        }
        $sample = imagecreatetruecolor(9, 8);
        imagecopyresampled($sample, $source, 0, 0, 0, 0, 9, 8, imagesx($source), imagesy($source));
        $bits = '';
        for ($y = 0; $y < 8; $y++) {
            for ($x = 0; $x < 8; $x++) {
                $left = imagecolorat($sample, $x, $y);
                $right = imagecolorat($sample, $x + 1, $y);
                $bits .= $this->luminance($left) > $this->luminance($right) ? '1' : '0';
            }
        }
        imagedestroy($sample);
        imagedestroy($source);

        return $bits;
    }

    private function luminance(int $color): float
    {
        return ((($color >> 16) & 0xFF) * 0.299) + ((($color >> 8) & 0xFF) * 0.587) + (($color & 0xFF) * 0.114);
    }

    private function hammingDistance(string $first, string $second): int
    {
        $distance = 0;
        for ($index = 0; $index < min(strlen($first), strlen($second)); $index++) {
            $distance += $first[$index] !== $second[$index] ? 1 : 0;
        }

        return $distance;
    }

    private function materialize(MediaAsset $asset, string $temporaryDirectory): array
    {
        try {
            return [Storage::disk($asset->disk)->path($asset->path), null];
        } catch (Throwable) {
            $temporary = $temporaryDirectory.'/source.'.pathinfo($asset->path, PATHINFO_EXTENSION);
            file_put_contents($temporary, Storage::disk($asset->disk)->get($asset->path));

            return [$temporary, $temporary];
        }
    }

    private function run(array $command): string
    {
        $process = new Process($command);
        $process->setTimeout(120);
        $process->mustRun();

        return $process->getOutput();
    }
}
