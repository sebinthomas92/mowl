<?php

namespace Tests\Feature;

use App\Models\MediaAsset;
use App\Models\Workspace;
use App\Services\MediaProcessor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class MediaProcessorTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_normalizes_an_uploaded_image_to_a_512px_derivative(): void
    {
        Storage::fake('local');
        [$workspace, $product] = $this->productFixture();
        $image = imagecreatetruecolor(120, 80);
        $temporary = tempnam(sys_get_temp_dir(), 'mowl-image').'.png';
        imagepng($image, $temporary);
        imagedestroy($image);
        Storage::disk('local')->put('originals/image.png', file_get_contents($temporary));
        $asset = MediaAsset::create([
            'workspace_id' => $workspace->id,
            'product_id' => $product->id,
            'type' => 'image',
            'disk' => 'local',
            'path' => 'originals/image.png',
            'original_name' => 'image.png',
            'mime_type' => 'image/png',
            'size_bytes' => filesize($temporary),
            'content_hash' => hash_file('sha256', $temporary),
        ]);

        app(MediaProcessor::class)->process($asset);

        $processed = $asset->fresh();
        $this->assertSame('processed', $processed->status);
        $this->assertCount(1, $processed->derivatives['images']);
        Storage::disk('local')->assertExists($processed->derivatives['images'][0]['path']);
        @unlink($temporary);
    }

    public function test_it_extracts_audio_and_a_bounded_deduplicated_frame_set_from_video(): void
    {
        Storage::fake('local');
        config()->set('services.openai.api_key', null);
        [$workspace, $product] = $this->productFixture();
        $temporary = tempnam(sys_get_temp_dir(), 'mowl-video').'.mp4';
        $process = new Process([
            config('campaigns.media.ffmpeg'), '-hide_banner', '-loglevel', 'error',
            '-f', 'lavfi', '-i', 'testsrc=size=640x360:rate=12',
            '-f', 'lavfi', '-i', 'sine=frequency=440:sample_rate=16000',
            '-t', '2', '-c:v', 'mpeg4', '-c:a', 'aac', '-pix_fmt', 'yuv420p', '-shortest', $temporary,
        ]);
        $process->mustRun();
        Storage::disk('local')->put('originals/video.mp4', file_get_contents($temporary));
        $asset = MediaAsset::create([
            'workspace_id' => $workspace->id,
            'product_id' => $product->id,
            'type' => 'video',
            'disk' => 'local',
            'path' => 'originals/video.mp4',
            'original_name' => 'video.mp4',
            'mime_type' => 'video/mp4',
            'size_bytes' => filesize($temporary),
            'content_hash' => hash_file('sha256', $temporary),
        ]);

        app(MediaProcessor::class)->process($asset);

        $processed = $asset->fresh();
        $frames = $processed->derivatives['frames'];
        $this->assertSame('processed', $processed->status);
        $this->assertGreaterThan(0, count($frames));
        $this->assertLessThanOrEqual(config('campaigns.media.max_frames'), count($frames));
        $this->assertSame('pending_credentials', $processed->metadata['transcription_status']);
        Storage::disk('local')->assertExists($processed->derivatives['audio']);
        foreach ($frames as $frame) {
            $this->assertSame(64, strlen($frame['perceptual_hash']));
            Storage::disk('local')->assertExists($frame['path']);
        }
        @unlink($temporary);
    }

    private function productFixture(): array
    {
        $workspace = Workspace::create(['name' => 'Media Agency']);
        $brand = $workspace->brands()->create(['name' => 'Media Brand']);

        return [$workspace, $brand->products()->create(['name' => 'Media Product'])];
    }
}
