<?php

namespace Tests\Feature;

use App\Livewire\ClientPortal;
use App\Livewire\ContentBrowser;
use App\Livewire\GlobalSearch;
use App\Livewire\ReviewQueue;
use App\Livewire\TeamIndex;
use App\Models\Brand;
use App\Models\CampaignPack;
use App\Models\ContentItem;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceInvitation;
use App\Services\ContentLibrary;
use App\Services\MockCampaignPackGenerator;
use App\Support\Membership;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

class RolesAndClientPortalTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_reviewer_approves_but_cannot_create_submit_or_see_costs(): void
    {
        [$owner, $workspace, $north] = $this->workspace();
        $item = $this->inReview($north, $owner);
        $reviewer = $this->member($workspace, 'reviewer');

        Livewire::actingAs($reviewer)->test(ReviewQueue::class)
            ->assertSee($item->title)
            ->assertSee('Reviewer')
            ->call('approve')
            ->assertHasNoErrors();
        $this->assertSame('approved', $item->fresh()->status);
        $this->assertSame($reviewer->id, $item->fresh()->latestVersion->reviewed_by_user_id);

        $draft = $north->contentItems()->where('status', 'draft')->first();
        Livewire::actingAs($reviewer)->test(ContentBrowser::class, ['productId' => $north->product_id])
            ->call('select', $draft->id)
            ->call('submit')
            ->assertForbidden();
        $this->assertSame('draft', $draft->fresh()->status);

        $this->actingAs($reviewer)->get(route('products.create'))->assertForbidden();
        $this->actingAs($reviewer)->get(route('campaign-packs.create'))->assertForbidden();
        $this->actingAs($reviewer)->get(route('usage.index'))->assertForbidden();
        $this->actingAs($reviewer)->get(route('products.index'))->assertOk()->assertDontSee('Add product')->assertDontSee('Pack credits');
        $this->actingAs($reviewer)->get(route('campaign-packs.index'))->assertOk()->assertDontSee('CREDITS REMAINING')->assertDontSee('COGS STATUS');
        $this->actingAs($reviewer)->get(route('campaign-packs.show', $north))->assertOk()->assertDontSee('tracked cost');
        $this->actingAs($reviewer)->get(route('workspace.settings'))->assertOk()->assertDontSee('overage credit');
        $this->actingAs($owner)->get(route('campaign-packs.index'))->assertOk()->assertSee('CREDITS REMAINING');
    }

    public function test_a_reviewer_cannot_decide_on_a_draft_through_the_url(): void
    {
        [$owner, $workspace, $north] = $this->workspace();
        $queued = $this->inReview($north, $owner);
        $draft = $north->contentItems()->where('status', 'draft')->firstOrFail();
        $reviewer = $this->member($workspace, 'reviewer');

        Livewire::actingAs($reviewer)->withQueryParams(['item' => $draft->id])->test(ReviewQueue::class)
            ->assertSee($queued->title)
            ->call('approve')
            ->assertHasNoErrors();

        $this->assertSame('draft', $draft->fresh()->status);
        $this->assertSame('approved', $queued->fresh()->status);
    }

    public function test_a_contributor_submits_but_cannot_approve(): void
    {
        [$owner, $workspace, $north] = $this->workspace();
        $this->inReview($north, $owner);
        $contributor = $this->member($workspace, 'member');

        Livewire::actingAs($contributor)->test(ReviewQueue::class)->call('approve')->assertForbidden();
    }

    public function test_a_brand_scope_hides_other_brands_everywhere(): void
    {
        [$owner, $workspace, $north, $kettle] = $this->workspace();
        $this->inReview($north, $owner);
        $kettleItem = $this->inReview($kettle, $owner);
        $scoped = $this->member($workspace, 'member', $north->product->brand_id);

        $this->actingAs($scoped)->get(route('products.index'))->assertOk()->assertSee('North Product')->assertDontSee('Kettle Product');
        $this->actingAs($scoped)->get(route('brands.index'))->assertOk()->assertSee('North Brand')->assertDontSee('Kettle Brand');
        $this->actingAs($scoped)->get(route('products.show', $kettle->product_id))->assertNotFound();
        $this->actingAs($scoped)->get(route('campaign-packs.show', $kettle))->assertNotFound();
        $this->actingAs($scoped)->get(route('products.hub-export', [$kettle->product_id, 'search']))->assertNotFound();
        $this->actingAs($scoped)->get(route('brands.edit', $kettle->product->brand_id))->assertNotFound();
        $this->actingAs($scoped)->get(route('brands.create'))->assertForbidden();
        $this->actingAs($scoped)->get(route('products.show', $north->product_id))->assertOk();

        Livewire::actingAs($scoped)->test(ReviewQueue::class)->assertSee('North Product')->assertDontSee('Kettle Product');
        Livewire::actingAs($scoped)->test(ContentBrowser::class)->assertDontSee('Kettle Product')->call('select', $kettleItem->id)->assertNotFound();
        Livewire::actingAs($scoped)->test(GlobalSearch::class)->set('query', 'Product')->assertSee('North Product')->assertDontSee('Kettle Product');
    }

    public function test_a_client_only_reaches_the_portal_for_their_brand(): void
    {
        [$owner, $workspace, $north, $kettle] = $this->workspace();
        $item = $this->inReview($north, $owner);
        $this->inReview($kettle, $owner);
        $client = $this->member($workspace, 'client', $north->product->brand_id);

        $this->actingAs($client)->get(route('products.index'))->assertRedirect(route('portal.index'));
        $this->actingAs($client)->get(route('review.index'))->assertRedirect(route('portal.index'));
        $this->actingAs($client)->get(route('products.show', $north->product_id))->assertNotFound();
        $this->actingAs($client)->get(route('campaign-packs.export', [$north, $north->versions()->first(), 'pdf']))->assertNotFound();

        $this->actingAs($client)->get(route('portal.index'))
            ->assertOk()
            ->assertSee('North Brand · review')
            ->assertSee($item->title)
            ->assertDontSee('Kettle Product')
            ->assertDontSee('Pack credits')
            ->assertDontSee('Ranked angles');

        Livewire::actingAs($client)->test(ClientPortal::class)
            ->call('requestChanges', $item->id)
            ->assertHasErrors('comments.'.$item->id)
            ->set('comments.'.$item->id, 'Lead with portability.')
            ->call('requestChanges', $item->id)
            ->assertHasNoErrors();
        $this->assertSame('changes', $item->fresh()->status);
        $this->assertSame('client', $item->fresh()->latestVersion->comments()->first()->author_role);
        $this->assertTrue($owner->notifications()->where('data->event', 'changes_requested')->exists());
    }

    public function test_a_client_cannot_decide_on_another_brands_item(): void
    {
        [$owner, $workspace, $north, $kettle] = $this->workspace();
        $this->inReview($north, $owner);
        $kettleItem = $this->inReview($kettle, $owner);
        $client = $this->member($workspace, 'client', $north->product->brand_id);

        Livewire::actingAs($client)->test(ClientPortal::class)->call('approve', $kettleItem->id)->assertNotFound();
        $this->assertSame('review', $kettleItem->fresh()->status);
        $this->actingAs($client)->get(route('portal.image', $kettleItem))->assertNotFound();
    }

    public function test_a_client_without_a_brand_sees_nothing(): void
    {
        [$owner, $workspace, $north] = $this->workspace();
        $item = $this->inReview($north, $owner);
        $client = $this->member($workspace, 'client');

        $this->actingAs($client)->get(route('portal.index'))->assertOk()->assertSee('Your access is not set up yet')->assertDontSee($item->title);
    }

    public function test_the_team_previews_the_client_view_without_deciding(): void
    {
        [$owner, $workspace, $north, $kettle] = $this->workspace();
        $item = $this->inReview($north, $owner);

        Livewire::actingAs($owner)->test(ClientPortal::class, ['previewBrand' => $north->product->brand_id])
            ->assertSee('Previewing as a client')
            ->assertSee($item->title)
            ->assertDontSee('Approve version')
            ->call('approve', $item->id)
            ->assertForbidden();
        $this->assertSame('review', $item->fresh()->status);
    }

    public function test_review_requests_reach_reviewers_in_scope_and_never_clients(): void
    {
        [$owner, $workspace, $north] = $this->workspace();
        $reviewer = $this->member($workspace, 'reviewer', $north->product->brand_id);
        $otherBrandReviewer = $this->member($workspace, 'reviewer', $workspace->brands()->where('name', 'Kettle Brand')->value('id'));
        $client = $this->member($workspace, 'client', $north->product->brand_id);

        $this->inReview($north, $owner);

        $this->assertTrue($reviewer->notifications()->where('data->event', 'review_requested')->exists());
        $this->assertFalse($otherBrandReviewer->notifications()->exists());
        $this->assertFalse($client->notifications()->exists());
    }

    public function test_an_owner_invites_a_client_without_using_a_seat(): void
    {
        [$owner, $workspace, $north] = $this->workspace();
        for ($seat = 2; $seat <= config('campaigns.seat_limit'); $seat++) {
            $this->member($workspace, 'member');
        }
        $brandId = $north->product->brand_id;

        Livewire::actingAs($owner)->test(TeamIndex::class)
            ->set('email', 'priya@client.example')->set('role', 'client')->set('scope', '')
            ->call('invite')
            ->assertHasErrors('scope')
            ->set('scope', (string) $brandId)
            ->call('invite')
            ->assertHasNoErrors()
            ->set('email', 'extra@agency.example')->set('role', 'member')->set('scope', '')
            ->call('invite')
            ->assertHasErrors('email');

        $invitation = WorkspaceInvitation::query()->where('email', 'priya@client.example')->firstOrFail();
        $this->assertSame('client', $invitation->role);
        $this->assertSame($brandId, $invitation->brand_id);

        $token = Str::random(48);
        $invitation->update(['token_hash' => hash('sha256', $token)]);
        $priya = User::factory()->create(['email' => 'priya@client.example']);
        $this->actingAs($priya)->get(route('invitations.accept', $token))->assertRedirect(route('portal.index'));

        $membership = Membership::of($priya, $workspace);
        $this->assertTrue($membership->isClient());
        $this->assertSame($brandId, $membership->brandId);
    }

    public function test_an_owner_changes_a_members_role_and_scope_but_not_their_own(): void
    {
        [$owner, $workspace, $north] = $this->workspace();
        $member = $this->member($workspace, 'member');
        $brandId = $north->product->brand_id;

        Livewire::actingAs($owner)->test(TeamIndex::class)
            ->set("access.{$member->id}.role", 'reviewer')
            ->set("access.{$member->id}.scope", (string) $brandId)
            ->call('updateAccess', $member->id)
            ->assertHasNoErrors()
            ->call('updateAccess', $owner->id)
            ->assertStatus(422);

        $membership = Membership::of($member, $workspace);
        $this->assertSame('reviewer', $membership->role);
        $this->assertSame($brandId, $membership->brandId);

        Livewire::actingAs($member)->test(TeamIndex::class)->call('updateAccess', $owner->id)->assertForbidden();
    }

    public function test_an_invitation_never_demotes_an_owner(): void
    {
        [$owner, $workspace] = $this->workspace();
        $token = Str::random(48);
        $workspace->invitations()->create([
            'invited_by_user_id' => $owner->id, 'email' => $owner->email, 'token_hash' => hash('sha256', $token), 'role' => 'client', 'expires_at' => now()->addDay(),
        ]);

        $this->actingAs($owner)->get(route('invitations.accept', $token))->assertStatus(409);
        $this->assertTrue(Membership::of($owner, $workspace)->isOwner());
    }

    /** @return array{0: User, 1: Workspace, 2: CampaignPack, 3: CampaignPack} */
    private function workspace(): array
    {
        $owner = User::factory()->create();
        $workspace = Workspace::create(['name' => 'Hollow Lane']);
        $workspace->users()->attach($owner, ['role' => 'owner']);

        return [$owner, $workspace, $this->pack($workspace, 'North'), $this->pack($workspace, 'Kettle')];
    }

    private function pack(Workspace $workspace, string $name): CampaignPack
    {
        $brand = Brand::create(['workspace_id' => $workspace->id, 'name' => "{$name} Brand"]);
        $product = $brand->products()->create(['name' => "{$name} Product", 'summary' => 'A directly supported product fact.']);
        $source = $product->sourceSnapshots()->create([
            'url' => 'https://example.com/'.strtolower($name), 'content_hash' => hash('sha256', $name), 'status' => 'ready',
            'extracted_content' => 'A directly supported product fact.', 'extracted_truth' => ['description' => 'A directly supported product fact.'],
            'approved_at' => now(),
        ]);
        $pack = CampaignPack::create(['product_id' => $product->id, 'source_snapshot_id' => $source->id, 'name' => "{$name} Pack", 'status' => 'draft', 'current_version' => 1, 'analysis_mode' => 'standard']);
        $result = app(MockCampaignPackGenerator::class)->generate($product, $source, ['description' => $product->summary]);
        $pack->versions()->create(['version' => 1, 'generator' => 'mock', 'content' => $result->content, 'evidence' => $result->evidence, 'compliance_flags' => $result->complianceFlags]);
        app(ContentLibrary::class)->syncPack($pack);

        return $pack;
    }

    /** Submits the pack's positioning item for review and returns it. */
    private function inReview(CampaignPack $pack, User $owner): ContentItem
    {
        $item = $pack->contentItems()->where('section', 'positioning')->with('latestVersion')->firstOrFail();
        $item->update(['title' => $pack->product->name.' positioning']);
        app(ContentLibrary::class)->submit($item->latestVersion, $owner);

        return $item->fresh();
    }

    private function member(Workspace $workspace, string $role, ?int $brandId = null): User
    {
        $user = User::factory()->create();
        $workspace->users()->attach($user, ['role' => $role, 'brand_id' => $brandId]);

        return $user;
    }
}
