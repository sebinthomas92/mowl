<?php

namespace Tests\Feature;

use App\Livewire\ContentBrowser;
use App\Models\BannerGenerationBatch;
use App\Models\CampaignPack;
use App\Models\ContentItem;
use App\Models\User;
use App\Models\Workspace;
use App\Services\ContentLibrary;
use App\Services\MockCampaignPackGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class OutputViewerTest extends TestCase
{
    use RefreshDatabase;

    public function test_outputs_are_grouped_by_pack_and_banner_set_and_the_viewer_opens_only_on_request(): void
    {
        [$owner, , $pack] = $this->fixture();
        $this->banners($pack, 2);

        Livewire::actingAs($owner)->test(ContentBrowser::class, ['productId' => $pack->product_id])
            ->assertSet('itemId', null)
            ->assertSee('Viewer Pack')
            ->assertSee('Launch pack outputs')
            ->assertSee('Banner set 1')
            ->assertSee('Banner set 2')
            ->assertDontSeeHtml('role="dialog"');
    }

    public function test_the_viewer_steps_through_outputs_in_grid_order_and_closes(): void
    {
        [$owner, , $pack] = $this->fixture();
        $this->banners($pack, 1);
        $items = $pack->contentItems()->orderBy('id')->get();
        $sections = $items->reject(fn (ContentItem $item) => $item->banner_creative_id)->values();
        $banners = $items->filter(fn (ContentItem $item) => $item->banner_creative_id)->values();

        Livewire::actingAs($owner)->test(ContentBrowser::class, ['productId' => $pack->product_id])
            ->call('select', $sections->last()->id)
            ->assertSeeHtml('role="dialog"')
            ->assertSee('7 of 10')
            ->call('step', 1)
            ->assertSet('itemId', $banners->first()->id)
            ->assertSee('Download PNG', false)
            ->call('step', -1)
            ->assertSet('itemId', $sections->last()->id)
            ->call('close')
            ->assertSet('itemId', null)
            ->assertDontSeeHtml('role="dialog"');
    }

    public function test_a_link_with_an_item_opens_the_viewer_on_it(): void
    {
        [$owner, , $pack] = $this->fixture();
        app(ContentLibrary::class)->syncPack($pack);
        $offers = $pack->contentItems()->where('section', 'offers')->firstOrFail();

        $this->actingAs($owner)->get(route('products.show', [$pack->product, 'tab' => 'content', 'item' => $offers->id]))
            ->assertOk()
            ->assertSee('role="dialog"', false)
            ->assertSee('Your decision on v1');
    }

    public function test_an_owner_approves_in_the_viewer_and_moves_to_the_next_output_waiting(): void
    {
        [$owner, , $pack] = $this->fixture();
        $pack->sourceSnapshot->update(['approved_by_user_id' => $owner->id, 'approved_at' => now()]);
        app(ContentLibrary::class)->syncPack($pack);
        [$first, $second] = $pack->contentItems()->orderBy('id')->get()->all();

        Livewire::actingAs($owner)->test(ContentBrowser::class, ['productId' => $pack->product_id])
            ->call('select', $first->id)
            ->set('decisionNote', 'Looks right.')
            ->call('approve')
            ->assertHasNoErrors()
            ->assertSet('itemId', $second->id)
            ->call('requestChanges')
            ->assertHasErrors('decisionNote')
            ->set('decisionNote', 'Lead with the stand, not the books.')
            ->call('requestChanges')
            ->assertHasNoErrors();

        $this->assertSame('approved', $first->fresh()->status);
        $this->assertSame('Looks right.', $first->latestVersion->comments()->value('body'));
        $this->assertSame('changes', $second->fresh()->status);
    }

    public function test_with_a_status_filter_on_a_decision_still_moves_to_the_next_output(): void
    {
        [$owner, , $pack] = $this->fixture();
        $pack->sourceSnapshot->update(['approved_by_user_id' => $owner->id, 'approved_at' => now()]);
        app(ContentLibrary::class)->syncPack($pack);
        [$first, $second] = $pack->contentItems()->orderBy('id')->get()->all();

        Livewire::actingAs($owner)->withQueryParams(['status' => 'draft'])->test(ContentBrowser::class, ['productId' => $pack->product_id])
            ->call('select', $first->id)
            ->call('approve')
            ->assertHasNoErrors()
            ->assertSet('itemId', $second->id)
            ->assertSee('1 of 6');
    }

    public function test_a_decision_is_refused_when_the_output_changed_after_the_viewer_showed_it(): void
    {
        [$owner, , $pack] = $this->fixture();
        $pack->sourceSnapshot->update(['approved_by_user_id' => $owner->id, 'approved_at' => now()]);
        $library = app(ContentLibrary::class);
        $library->syncPack($pack);
        $positioning = $pack->contentItems()->where('section', 'positioning')->firstOrFail();

        $viewer = Livewire::actingAs($owner)->test(ContentBrowser::class, ['productId' => $pack->product_id])->call('select', $positioning->id);
        $library->saveDraft($positioning->latestVersion, ['value_proposition' => 'A directly supported viewer product fact.']);

        $viewer->call('approve')->assertHasErrors('decision');
        $this->assertSame('draft', $positioning->fresh()->status);

        $viewer->call('approve')->assertHasNoErrors();
        $this->assertSame('approved', $positioning->fresh()->status);
    }

    public function test_a_blocked_approval_shows_the_reason_in_the_viewer(): void
    {
        [$owner, , $pack] = $this->fixture();
        app(ContentLibrary::class)->syncPack($pack);
        $first = $pack->contentItems()->orderBy('id')->firstOrFail();

        Livewire::actingAs($owner)->test(ContentBrowser::class, ['productId' => $pack->product_id])
            ->call('select', $first->id)
            ->call('approve')
            ->assertHasErrors('decision')
            ->assertSet('itemId', $first->id);

        $this->assertSame('draft', $first->fresh()->status);
    }

    public function test_a_contributor_cannot_decide_in_the_viewer(): void
    {
        [, $workspace, $pack] = $this->fixture();
        $member = User::factory()->create();
        $workspace->users()->attach($member, ['role' => 'member']);
        app(ContentLibrary::class)->syncPack($pack);
        $first = $pack->contentItems()->orderBy('id')->firstOrFail();

        Livewire::actingAs($member)->test(ContentBrowser::class, ['productId' => $pack->product_id])
            ->call('select', $first->id)
            ->assertDontSee('Your decision on v1')
            ->assertSee('Submit v1 for review')
            ->call('approve')
            ->assertForbidden();
    }

    /** Adds finished banner sets of three to the pack and syncs its items. */
    private function banners(CampaignPack $pack, int $sets): void
    {
        $version = $pack->versions()->firstOrFail();
        foreach (range(1, $sets) as $set) {
            $batch = BannerGenerationBatch::create([
                'workspace_id' => $pack->product->brand->workspace_id, 'campaign_pack_id' => $pack->id, 'campaign_pack_version_id' => $version->id,
                'kind' => 'included', 'requested_count' => 3, 'credit_cost' => 0, 'status' => 'completed',
            ]);
            foreach (['Problem / solution', 'Product hero', 'Lifestyle'] as $sequence => $direction) {
                $batch->creatives()->create([
                    'campaign_pack_id' => $pack->id, 'campaign_pack_version_id' => $version->id, 'sequence' => $sequence + 1,
                    'direction' => $direction, 'layout' => 'top_left', 'headline' => "Set {$set} {$direction}", 'supporting_text' => 'Small-batch minimal design',
                    'cta' => 'Shop now', 'prompt' => 'A desk', 'status' => 'completed', 'disk' => 'local', 'output_path' => "banners/{$set}-{$sequence}.png", 'width' => 1080, 'height' => 1350,
                ]);
            }
        }
        app(ContentLibrary::class)->syncPack($pack);
    }

    private function fixture(): array
    {
        $owner = User::factory()->create();
        $workspace = Workspace::create(['name' => 'Viewer Agency']);
        $workspace->users()->attach($owner, ['role' => 'owner']);
        $product = $workspace->brands()->create(['name' => 'Viewer Brand'])->products()->create(['name' => 'Viewer Product', 'summary' => 'A directly supported viewer product fact.']);
        $source = $product->sourceSnapshots()->create([
            'url' => 'https://example.com/viewer', 'content_hash' => hash('sha256', 'viewer'), 'status' => 'ready',
            'extracted_content' => 'A directly supported viewer product fact.', 'extracted_truth' => ['description' => 'A directly supported viewer product fact.'],
        ]);
        $pack = CampaignPack::create(['product_id' => $product->id, 'source_snapshot_id' => $source->id, 'name' => 'Viewer Pack', 'status' => 'draft', 'current_version' => 1, 'analysis_mode' => 'standard']);
        $result = app(MockCampaignPackGenerator::class)->generate($product, $source, ['description' => $product->summary]);
        $pack->versions()->create(['version' => 1, 'generator' => 'mock', 'content' => $result->content, 'evidence' => $result->evidence, 'compliance_flags' => $result->complianceFlags]);

        return [$owner, $workspace, $pack];
    }
}
