<?php

use App\Models\CampaignPack;
use App\Models\User;
use App\Models\Workspace;
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
$manifest = ['workspace' => $workspace->id, 'otherWorkspace' => $other->id];
foreach (['review', 'approved', 'reject', 'variation'] as $kind) {
    $product = $brand->products()->create(['name' => ucfirst($kind).' Browser Product', 'price' => '$25', 'summary' => 'A directly supported browser product fact.']);
    $source = $product->sourceSnapshots()->create(['url' => 'https://example.com/browser-product', 'canonical_url' => 'https://example.com/browser-product', 'content_hash' => hash('sha256', $kind), 'status' => 'ready', 'extracted_content' => $product->summary, 'extracted_truth' => ['description' => $product->summary], 'fetched_at' => now()]);
    $pack = CampaignPack::create(['product_id' => $product->id, 'source_snapshot_id' => $source->id, 'name' => ucfirst($kind).' Browser Pack', 'status' => $kind === 'approved' ? 'approved' : 'draft', 'current_version' => 1, 'analysis_mode' => 'standard']);
    $result = app(MockCampaignPackGenerator::class)->generate($product, $source, ['description' => $product->summary]);
    $version = $pack->versions()->create(['version' => 1, 'generator' => 'mock', 'content' => $result->content, 'evidence' => $result->evidence, 'compliance_flags' => $result->complianceFlags, 'review_status' => $kind === 'approved' ? 'approved' : 'draft', 'reviewed_at' => $kind === 'approved' ? now() : null]);
    $manifest[$kind] = ['product' => $product->id, 'pack' => $pack->id, 'version' => $version->id];
    if ($kind === 'approved') {
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
    }

}
file_put_contents(__DIR__.'/../../.e2e/runtime/fixtures.json', json_encode($manifest));
