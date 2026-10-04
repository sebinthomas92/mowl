<?php

use App\Models\CampaignGenerationJob;
use App\Models\CampaignPack;
use App\Models\User;
use App\Models\Workspace;
use App\Services\BannerBatchCreator;
use App\Services\MockCampaignPackGenerator;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Storage;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
if (getenv('APP_ENV') !== 'e2e' || getenv('DB_DATABASE') !== realpath(__DIR__.'/../../.e2e/runtime').'/database.sqlite') {
    throw new RuntimeException('E2E bootstrap only operates on its own disposable database.');
}
$app->useStoragePath(getenv('E2E_STORAGE'));
$app->loadEnvironmentFrom('e2e-unused.env');
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

if (($argv[1] ?? '') === 'migrate') {
    exit($kernel->call('migrate', ['--force' => true]));
}
if (($argv[1] ?? '') !== 'seed') {
    throw new RuntimeException('Unknown E2E bootstrap action.');
}

$workspace = Workspace::create(['name' => 'Browser Agency']);
$other = Workspace::create(['name' => 'Other Agency']);
$users = [];
foreach (['owner', 'member', 'outsider', 'concierge'] as $role) {
    $user = User::create(['name' => ucfirst($role).' Browser', 'email' => $role.'@example.test', 'password' => 'Browser-test-123!']);
    ($role === 'outsider' ? $other : $workspace)->users()->attach($user, ['role' => $role === 'member' ? 'member' : 'owner']);
    $users[$role] = $user;
}
$other->users()->attach($users['owner'], ['role' => 'member']);
foreach ([$workspace, $other] as $item) {
    $item->credits()->create(['amount' => 50, 'event' => 'beta_allocation', 'description' => 'Disposable browser fixture']);
}
$brand = $workspace->brands()->create(['name' => 'Browser Brand', 'website' => 'https://example.com']);
$manifest = ['workspace' => $workspace->id, 'otherWorkspace' => $other->id, 'processingMode' => config('campaigns.processing_mode')];
foreach (['review', 'approved', 'reject', 'variation', 'bannerRetry', 'bannerStale'] as $kind) {
    $approved = in_array($kind, ['approved', 'bannerRetry', 'bannerStale']);
    $product = $brand->products()->create(['name' => ucfirst($kind).' Browser Product', 'price' => '$25', 'summary' => 'A directly supported browser product fact.']);
    $source = $product->sourceSnapshots()->create(['url' => 'https://example.com/browser-product', 'canonical_url' => 'https://example.com/browser-product', 'content_hash' => hash('sha256', $kind), 'status' => 'ready', 'extracted_content' => $product->summary, 'extracted_truth' => ['description' => $product->summary], 'fetched_at' => now()]);
    $pack = CampaignPack::create(['product_id' => $product->id, 'source_snapshot_id' => $source->id, 'name' => ucfirst($kind).' Browser Pack', 'status' => $approved ? 'approved' : 'draft', 'current_version' => 1, 'analysis_mode' => 'standard']);
    $result = app(MockCampaignPackGenerator::class)->generate($product, $source, ['description' => $product->summary]);
    $version = $pack->versions()->create(['version' => 1, 'generator' => 'mock', 'content' => $result->content, 'evidence' => $result->evidence, 'compliance_flags' => $result->complianceFlags, 'review_status' => $approved ? 'approved' : 'draft', 'reviewed_at' => $approved ? now() : null]);
    $manifest[$kind] = ['product' => $product->id, 'pack' => $pack->id, 'version' => $version->id];
    if ($approved) {
        $image = imagecreatetruecolor(600, 800);
        imagefill($image, 0, 0, imagecolorallocate($image, 30, 80, 120));
        ob_start();
        imagepng($image);
        $bytes = ob_get_clean();
        imagedestroy($image);
        $path = "campaign-media/{$workspace->id}/{$product->id}/browser-product.png";
        Storage::disk('local')->put($path, $bytes);
        $media = $product->mediaAssets()->create([
            'workspace_id' => $workspace->id, 'source_snapshot_id' => $source->id,
            'type' => 'image', 'disk' => 'local', 'path' => $path, 'original_name' => 'browser-product.png',
            'mime_type' => 'image/png', 'size_bytes' => strlen($bytes), 'content_hash' => hash('sha256', $bytes), 'status' => 'ready',
            'metadata' => ['origin' => 'product_page', 'source_url' => 'https://example.com/browser-product.png', 'candidate_rank' => 1, 'width' => 600, 'height' => 800, 'auto_imported' => true],
        ]);
        $manifest[$kind]['media'] = $media->id;
        $manifest[$kind]['imageFile'] = Storage::disk('local')->path($path);
        if (in_array($kind, ['bannerRetry', 'bannerStale'])) {
            $batch = app(BannerBatchCreator::class)->createIncluded($workspace, $pack, $users['owner']);
            $batch->creatives()->update([
                'status' => $kind === 'bannerRetry' ? 'failed' : 'processing',
                'attempts' => $kind === 'bannerRetry' ? 3 : 1,
                'error_message' => $kind === 'bannerRetry' ? 'Disposable exhausted banner fixture.' : null,
                'updated_at' => now()->subMinutes(15),
            ]);
            $batch->update(['status' => $kind === 'bannerRetry' ? 'failed' : 'running']);
            $manifest[$kind]['batch'] = $batch->id;
        }
    }

}
// These are durable failed/retrying/stale states, not fake responses. Recovery uses the real jobs.
foreach (['failedRetry' => 'failed', 'cronRetry' => 'retrying', 'cronStale' => 'processing'] as $kind => $status) {
    $product = $brand->products()->create(['name' => $kind.' Browser Product', 'summary' => 'A disposable recovery product.']);
    $source = $product->sourceSnapshots()->create(['url' => getenv('E2E_PUBLIC_PRODUCT_URL'), 'content_hash' => hash('sha256', $kind), 'status' => 'pending']);
    $pack = CampaignPack::create(['product_id' => $product->id, 'source_snapshot_id' => $source->id, 'name' => $kind.' Browser Pack', 'status' => $status === 'failed' ? 'failed' : 'processing', 'current_version' => 0, 'analysis_mode' => 'standard', 'credit_cost' => 1]);
    $job = CampaignGenerationJob::create(['workspace_id' => $workspace->id, 'campaign_pack_id' => $pack->id, 'source_snapshot_id' => $source->id, 'status' => $status, 'phase' => $status === 'failed' ? 'failed' : 'retry_wait', 'credit_cost' => 1, 'attempts' => $status === 'failed' ? 3 : 1, 'error_message' => 'Disposable interrupted generation fixture.']);
    CampaignGenerationJob::whereKey($job->id)->update(['updated_at' => now()->subMinutes(15)]);
    $workspace->credits()->create(['campaign_pack_id' => $pack->id, 'campaign_generation_job_id' => $job->id, 'amount' => -1, 'event' => 'generation', 'description' => 'Disposable recovery reservation']);
    if ($status === 'failed') {
        $workspace->credits()->create(['campaign_pack_id' => $pack->id, 'campaign_generation_job_id' => $job->id, 'amount' => 1, 'event' => 'generation_refund', 'description' => 'Disposable exhausted-job refund']);
    }
    $manifest[$kind] = ['product' => $product->id, 'pack' => $pack->id, 'job' => $job->id];
}
file_put_contents(__DIR__.'/../../.e2e/runtime/fixtures.json', json_encode($manifest));
