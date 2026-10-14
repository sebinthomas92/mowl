<?php

namespace Tests\Feature;

use App\Livewire\CampaignPackIndex;
use App\Livewire\CampaignWorkspace;
use App\Livewire\GlobalSearch;
use App\Livewire\InboxDrawer;
use App\Models\CampaignPack;
use App\Models\User;
use App\Models\Workspace;
use App\Notifications\CampaignPackActivity;
use App\Services\MockCampaignPackGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Tests\TestCase;

class AppShellTest extends TestCase
{
    use RefreshDatabase;

    public function test_shell_shows_launch_pack_naming_review_count_and_inbox(): void
    {
        [$owner, , $pack] = $this->fixture('Northline');
        $pack->update(['status' => 'review']);

        $this->actingAs($owner)->get(route('campaign-packs.index'))
            ->assertOk()
            ->assertSee('Launch packs')
            ->assertDontSee('Campaign packs')
            ->assertSee('Review queue, 1 waiting')
            ->assertSee('Search products and content')
            ->assertSee('Inbox, 0 unread');
    }

    public function test_review_queue_link_filters_packs_waiting_for_review(): void
    {
        [$owner, $workspace, $pack] = $this->fixture('Northline');
        $pack->update(['status' => 'review']);
        $other = $this->packFor($workspace, 'Quiet Product');

        Livewire::actingAs($owner)->withQueryParams(['status' => 'review'])->test(CampaignPackIndex::class)
            ->assertSee('Showing packs waiting for review')
            ->assertSee($pack->product->name)
            ->assertDontSee($other->product->name);
    }

    public function test_search_only_returns_the_current_workspace(): void
    {
        [$owner] = $this->fixture('Northline');
        $this->fixture('Rival');

        Livewire::actingAs($owner)->test(GlobalSearch::class)
            ->set('query', 'lamp')
            ->assertSee('Northline Lamp')
            ->assertSee('Northline Lamp launch pack')
            ->assertDontSee('Rival Lamp')
            ->set('query', 'zz-no-match')
            ->assertSee('Nothing matches');
    }

    public function test_search_treats_wildcards_literally(): void
    {
        [$owner] = $this->fixture('Northline');

        Livewire::actingAs($owner)->test(GlobalSearch::class)
            ->set('query', '%%')
            ->assertDontSee('Northline Lamp');
    }

    public function test_review_request_notifies_owners_and_comments_notify_the_workspace(): void
    {
        [$owner, $workspace, $pack] = $this->fixture('Northline');
        $member = User::factory()->create(['name' => 'Mira Member']);
        $workspace->users()->attach($member, ['role' => 'member']);
        $secondOwner = User::factory()->create();
        $workspace->users()->attach($secondOwner, ['role' => 'owner']);

        Livewire::actingAs($member)->test(CampaignWorkspace::class, ['pack' => $pack])->call('requestReview');

        $this->assertSame(1, $owner->notifications()->count());
        $this->assertSame(1, $secondOwner->notifications()->count());
        $this->assertSame(0, $member->notifications()->count());
        $this->assertSame($workspace->id, $owner->notifications()->first()->workspace_id);
        $this->assertSame('Mira Member asked for a review of Northline Lamp v1', $owner->notifications()->first()->data['title']);

        Livewire::actingAs($owner)->test(CampaignWorkspace::class, ['pack' => $pack])
            ->set('commentBody', 'Check the price line.')
            ->call('addComment');

        $this->assertSame(1, $member->notifications()->count());
        $this->assertSame(2, $secondOwner->notifications()->count());
        $this->assertSame(1, $owner->notifications()->count());
    }

    public function test_inbox_is_scoped_to_the_current_workspace_and_marks_read(): void
    {
        [$owner, $workspace, $pack] = $this->fixture('Northline');
        [, $otherWorkspace, $otherPack] = $this->fixture('Rival');
        $otherWorkspace->users()->attach($owner, ['role' => 'member']);
        $actor = User::factory()->create(['name' => 'Ana Actor']);
        $owner->notify(new CampaignPackActivity('review_requested', $pack->versions()->first(), $actor));
        $owner->notify(new CampaignPackActivity('approved', $otherPack->versions()->first(), $actor));

        $this->actingAs($owner)->withSession(['current_workspace_id' => $workspace->id]);

        $inbox = Livewire::test(InboxDrawer::class)
            ->assertSee('Ana Actor asked for a review of Northline Lamp v1')
            ->assertDontSee('Rival Lamp')
            ->assertSee('Inbox, 1 unread')
            ->call('markAllRead')
            ->assertSee('Inbox, 0 unread');

        $this->assertNull($owner->notifications()->where('workspace_id', $otherWorkspace->id)->value('read_at'));

        $foreign = $owner->notifications()->where('workspace_id', $otherWorkspace->id)->value('id');
        $inbox->call('open', $foreign)->assertNotFound();
    }

    public function test_pages_and_reviews_still_work_before_the_notifications_migration_runs(): void
    {
        [$owner, , $pack] = $this->fixture('Northline');
        Schema::drop('notifications');

        $this->actingAs($owner)->get(route('campaign-packs.index'))->assertOk()->assertSee('Inbox, 0 unread');
        Livewire::actingAs($owner)->test(CampaignWorkspace::class, ['pack' => $pack])->call('requestReview')->assertOk();
    }

    private function fixture(string $prefix): array
    {
        $owner = User::factory()->create();
        $workspace = Workspace::create(['name' => "{$prefix} Agency"]);
        $workspace->users()->attach($owner, ['role' => 'owner']);
        $pack = $this->packFor($workspace, "{$prefix} Lamp");

        return [$owner, $workspace, $pack];
    }

    private function packFor(Workspace $workspace, string $productName): CampaignPack
    {
        $brand = $workspace->brands()->firstOrCreate(['name' => "{$workspace->name} Brand"]);
        $product = $brand->products()->create(['name' => $productName, 'summary' => 'A directly supported product fact.']);
        $source = $product->sourceSnapshots()->create([
            'url' => 'https://example.com/'.str($productName)->slug(),
            'content_hash' => hash('sha256', $productName),
            'status' => 'ready',
            'extracted_content' => 'A directly supported product fact.',
            'extracted_truth' => ['description' => 'A directly supported product fact.'],
        ]);
        $pack = CampaignPack::create(['product_id' => $product->id, 'source_snapshot_id' => $source->id, 'name' => "{$productName} launch pack", 'status' => 'draft', 'current_version' => 1, 'analysis_mode' => 'standard']);
        $result = app(MockCampaignPackGenerator::class)->generate($product, $source, ['description' => $product->summary]);
        $pack->versions()->create(['version' => 1, 'generator' => 'mock', 'content' => $result->content, 'evidence' => $result->evidence, 'compliance_flags' => $result->complianceFlags]);

        return $pack;
    }
}
