<?php

namespace Database\Seeders;

use App\Models\Brand;
use App\Models\CampaignPack;
use App\Models\Product;
use App\Models\SourceSnapshot;
use App\Models\User;
use App\Models\Workspace;
use App\Services\MockCampaignPackGenerator;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Local demo data for designing and reviewing screens.
 *
 * php artisan db:seed --class=DemoSeeder
 * Sign in as demo@marketingowl.test / password.
 */
class DemoSeeder extends Seeder
{
    public function run(): void
    {
        $user = User::firstOrCreate(
            ['email' => 'demo@marketingowl.test'],
            ['name' => 'Sam Dorey', 'password' => 'password'],
        );

        $workspace = Workspace::firstOrCreate(['name' => 'Hollow Lane Studio']);
        if (! $workspace->users()->whereKey($user->id)->exists()) {
            $workspace->users()->attach($user, ['role' => 'owner']);
        }
        $workspace->credits()->firstOrCreate(
            ['event' => 'beta_allocation'],
            ['amount' => config('campaigns.monthly_credits'), 'description' => 'Initial beta pack credits'],
        );

        $catalog = [
            'Northline Goods' => [
                'website' => 'https://demo.northlinegoods.example',
                'products' => [
                    ['Fold Reading Stand', '$54.00', 'A foldable stand for books and tablets up to 12". Six angles, folds flat for travel.', 'fold-reading-stand'],
                    ['Trail Bottle 750', '$32.00', 'Insulated 750 ml bottle that keeps drinks cold for 24 hours.', 'trail-bottle'],
                ],
            ],
            'Kettle & Co.' => [
                'website' => 'https://demo.kettleco.example',
                'products' => [
                    ['Desk Lamp Mini', '$45.00', 'Compact LED desk lamp with three warmth settings.', 'desk-lamp-mini'],
                ],
            ],
        ];

        $generator = app(MockCampaignPackGenerator::class);

        foreach ($catalog as $brandName => $brandData) {
            $brand = Brand::firstOrCreate(
                ['workspace_id' => $workspace->id, 'name' => $brandName],
                ['website' => $brandData['website']],
            );

            foreach ($brandData['products'] as [$name, $price, $summary, $slug]) {
                $product = Product::firstOrCreate(
                    ['brand_id' => $brand->id, 'name' => $name],
                    ['price' => $price, 'summary' => $summary],
                );

                if ($product->campaignPacks()->exists()) {
                    continue;
                }

                // One transaction per product so an interrupted run never leaves a pack without its versions.
                DB::transaction(function () use ($brandData, $brandName, $product, $user, $generator, $name, $price, $summary, $slug) {
                    $url = "{$brandData['website']}/{$slug}";
                    $truth = ['name' => $name, 'description' => $summary, 'price' => $price, 'currency' => 'USD', 'availability' => 'InStock', 'brand' => $brandName];
                    $source = SourceSnapshot::create([
                        'product_id' => $product->id,
                        'type' => 'url',
                        'url' => $url,
                        'canonical_url' => $url,
                        'title' => $name,
                        'content_hash' => hash('sha256', $url),
                        'status' => 'ready',
                        'extracted_content' => $summary,
                        'extracted_truth' => $truth,
                        'approved_by_user_id' => $user->id,
                        'approved_at' => now(),
                        'fetched_at' => now(),
                    ]);

                    $result = $generator->generate($product, $source, ['product_truth' => $truth, 'description' => $summary]);

                    $pack = CampaignPack::create([
                        'product_id' => $product->id,
                        'source_snapshot_id' => $source->id,
                        'name' => "{$name} launch pack",
                        'status' => 'review',
                        'current_version' => 2,
                        'analysis_mode' => 'standard',
                        'credit_cost' => 1,
                        'estimated_cost' => 0.018,
                    ]);

                    $version = fn (int $number, string $status) => [
                        'version' => $number,
                        'content' => $result->content,
                        'evidence' => $result->evidence,
                        'compliance_flags' => $result->complianceFlags,
                        'generator' => $result->provider,
                        'review_status' => $status,
                    ];

                    $pack->versions()->create($version(1, 'approved') + ['reviewed_by_user_id' => $user->id, 'reviewed_at' => now()]);
                    $pack->versions()->create($version(2, 'review'));
                });
            }
        }
    }
}
