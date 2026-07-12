<?php

namespace Tests\Feature;

use App\Models\MediaAsset;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class MediaAssetDownloadTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_workspace_member_can_download_a_private_local_media_asset(): void
    {
        Storage::fake('local');
        [$user, $asset] = $this->assetFixture();
        Storage::disk('local')->put($asset->path, 'private media');

        $this->actingAs($user)
            ->get(route('media-assets.download', $asset))
            ->assertOk()
            ->assertHeader('content-disposition', 'attachment; filename=demo.png');
    }

    public function test_another_workspace_cannot_download_private_media(): void
    {
        Storage::fake('local');
        [, $asset] = $this->assetFixture();
        Storage::disk('local')->put($asset->path, 'private media');
        $otherUser = User::factory()->create();
        $otherWorkspace = Workspace::create(['name' => 'Other Agency']);
        $otherWorkspace->users()->attach($otherUser, ['role' => 'owner']);

        $this->actingAs($otherUser)
            ->get(route('media-assets.download', $asset))
            ->assertNotFound();
    }

    private function assetFixture(): array
    {
        $user = User::factory()->create();
        $workspace = Workspace::create(['name' => 'Media Agency']);
        $workspace->users()->attach($user, ['role' => 'owner']);
        $product = $workspace->brands()->create(['name' => 'Media Brand'])->products()->create(['name' => 'Media Product']);
        $asset = MediaAsset::create([
            'workspace_id' => $workspace->id,
            'product_id' => $product->id,
            'type' => 'image',
            'disk' => 'local',
            'path' => 'campaign-media/'.$workspace->id.'/'.$product->id.'/originals/demo.png',
            'original_name' => 'demo.png',
            'mime_type' => 'image/png',
            'size_bytes' => 13,
            'content_hash' => hash('sha256', 'demo-media'),
        ]);

        return [$user, $asset];
    }
}
