<?php

namespace Tests\Feature;

use App\Jobs\ProcessMediaAsset;
use App\Models\MediaAsset;
use App\Models\Workspace;
use App\Services\MediaProcessor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class ProcessMediaAssetTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_failed_worker_attempt_records_asset_failure_telemetry(): void
    {
        $workspace = Workspace::create(['name' => 'Media Agency']);
        $product = $workspace->brands()->create(['name' => 'Media Brand'])->products()->create(['name' => 'Media Product']);
        $asset = MediaAsset::create([
            'workspace_id' => $workspace->id,
            'product_id' => $product->id,
            'type' => 'image',
            'disk' => 'local',
            'path' => 'missing/image.png',
            'original_name' => 'image.png',
            'mime_type' => 'image/png',
            'size_bytes' => 1,
            'content_hash' => hash('sha256', 'missing-image'),
        ]);

        try {
            (new ProcessMediaAsset($asset->id))->handle(app(MediaProcessor::class));
            $this->fail('The worker should fail when the original cannot be materialized.');
        } catch (RuntimeException) {
        }

        $asset->refresh();
        $this->assertSame('failed', $asset->status);
        $this->assertSame(1, $asset->processing_attempts);
        $this->assertNotNull($asset->processing_duration_ms);
        $this->assertNotEmpty($asset->error_message);
    }
}
