<?php

namespace Database\Seeders;

use App\Models\Brand;
use App\Models\CampaignPack;
use App\Models\Product;
use App\Models\SourceSnapshot;
use App\Models\User;
use App\Models\Workspace;
use App\Notifications\CampaignPackActivity;
use App\Services\ContentLibrary;
use App\Services\MockCampaignPackGenerator;
use App\Services\ProductKnowledge;
use App\Support\Membership;
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
        $reviewer = User::firstOrCreate(
            ['email' => 'priya@marketingowl.test'],
            ['name' => 'Priya Raman', 'password' => 'password'],
        );
        if (! $workspace->users()->whereKey($reviewer->id)->exists()) {
            $workspace->users()->attach($reviewer, ['role' => 'member']);
        }
        $workspace->credits()->firstOrCreate(
            ['event' => 'beta_allocation'],
            ['amount' => config('campaigns.monthly_credits'), 'description' => 'Initial beta pack credits'],
        );

        $catalog = [
            'Northline Goods' => [
                'website' => 'https://demo.northlinegoods.example',
                'profile' => ['voice' => 'Calm, practical, a little dry. Short sentences.', 'default_audience' => 'Home office workers', 'palette' => ['#1F3A34', '#E9E1D3', '#C7643A']],
                'products' => [
                    ['Fold Reading Stand', '$54.00', 'A foldable stand for books and tablets up to 12". Six angles, folds flat for travel.', 'fold-reading-stand'],
                    ['Trail Bottle 750', '$32.00', 'Insulated 750 ml bottle that keeps drinks cold for 24 hours.', 'trail-bottle'],
                ],
            ],
            'Kettle & Co.' => [
                'website' => 'https://demo.kettleco.example',
                'profile' => ['voice' => 'Warm and playful, never cute.', 'default_audience' => 'Students and makers', 'palette' => ['#3B2E5A', '#F4E7D4', '#E3A23B']],
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
            if (Brand::profileAvailable() && ! $brand->voice) {
                $brand->update($brandData['profile'] + ['primary_color' => $brandData['profile']['palette'][0]]);
            }

            foreach ($brandData['products'] as [$name, $price, $summary, $slug]) {
                $product = Product::firstOrCreate(
                    ['brand_id' => $brand->id, 'name' => $name],
                    ['price' => $price, 'summary' => $summary],
                );

                if ($product->campaignPacks()->exists()) {
                    continue;
                }

                // One transaction per product so an interrupted run never leaves a pack without its versions.
                $review = DB::transaction(function () use ($brandData, $brandName, $product, $user, $generator, $name, $price, $summary, $slug) {
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

                    return $pack->versions()->create($version(2, 'review'));
                });

                // Seed the inbox: the teammate asked for a review of each v2.
                $user->notify(new CampaignPackActivity('review_requested', $review, $reviewer));

                // Split the pack into content items and put a revised positioning in the review queue.
                if (ContentLibrary::available()) {
                    $library = app(ContentLibrary::class);
                    $library->syncPack($review->campaignPack);
                    $positioning = $review->campaignPack->contentItems()->where('section', 'positioning')->first();
                    if ($positioning && $positioning->status === 'approved') {
                        $library->submit($library->revise($positioning, $reviewer), $reviewer);
                    }
                }
            }
        }

        $this->seedKnowledgeConflict($workspace);
        $this->seedRoles($workspace);
    }

    /** A brand-scoped reviewer and a client account for Northline Goods, so every role can be tried locally. */
    private function seedRoles(Workspace $workspace): void
    {
        $northline = $workspace->brands()->where('name', 'Northline Goods')->first();
        if (! $northline || ! Membership::scopesAvailable()) {
            return;
        }
        foreach ([['rhea@marketingowl.test', 'Rhea Kapoor', 'reviewer'], ['client@marketingowl.test', 'Mo Okafor', 'client']] as [$email, $name, $role]) {
            $user = User::firstOrCreate(['email' => $email], ['name' => $name, 'password' => 'password']);
            if (! $workspace->users()->whereKey($user->id)->exists()) {
                $workspace->users()->attach($user, ['role' => $role, 'brand_id' => $northline->id]);
            }
        }
    }

    /** Give the first product a refreshed source whose price changed, so Knowledge shows a decision to make. */
    private function seedKnowledgeConflict(Workspace $workspace): void
    {
        $product = Product::whereHas('brand', fn ($query) => $query->where('workspace_id', $workspace->id))->orderBy('id')->first();
        if (! $product || ! ProductKnowledge::available() || $product->facts()->exists()) {
            return;
        }

        $knowledge = app(ProductKnowledge::class);
        $previous = $product->sourceSnapshots()->where('status', 'ready')->latest('id')->first();
        $knowledge->facts($product);
        if (! $previous) {
            return;
        }

        $truth = ['price' => '64.00'] + $previous->extracted_truth;
        $refreshed = $previous->replicate(['approved_by_user_id', 'approved_at']);
        $refreshed->fill(['extracted_truth' => $truth, 'content_hash' => hash('sha256', json_encode($truth)), 'fetched_at' => now(), 'refreshed_from_snapshot_id' => $previous->id])->save();
        $knowledge->syncFromSource($product, $refreshed);
    }
}
