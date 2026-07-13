<?php

namespace Tests\Feature;

use App\Jobs\GenerateCampaignPack;
use App\Livewire\Auth\Register;
use App\Livewire\ConciergeIndex;
use App\Livewire\TeamIndex;
use App\Livewire\WorkspaceSettings;
use App\Models\Brand;
use App\Models\CampaignGenerationJob;
use App\Models\CampaignPack;
use App\Models\Product;
use App\Models\SourceSnapshot;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceCredit;
use App\Models\WorkspaceInvitation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

class TeamManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_owner_can_create_a_hashed_seven_day_invitation(): void
    {
        [$owner, $workspace] = $this->workspaceUser('owner');

        Livewire::actingAs($owner)
            ->test(TeamIndex::class)
            ->set('email', 'Buyer@Agency.com')
            ->call('invite')
            ->assertHasNoErrors()
            ->assertSet('email', '')
            ->assertSet('inviteUrl', fn (?string $url): bool => str_contains((string) $url, '/invitations/'));

        $invitation = WorkspaceInvitation::firstOrFail();
        $this->assertSame($workspace->id, $invitation->workspace_id);
        $this->assertSame('buyer@agency.com', $invitation->email);
        $this->assertSame(64, strlen($invitation->token_hash));
        $this->assertTrue($invitation->expires_at->between(now()->addDays(6), now()->addDays(8)));
    }

    public function test_a_member_cannot_manage_workspace_seats(): void
    {
        [$member] = $this->workspaceUser('member');

        Livewire::actingAs($member)
            ->test(TeamIndex::class)
            ->set('email', 'new@agency.com')
            ->call('invite')
            ->assertForbidden();
    }

    public function test_pending_invitations_reserve_the_five_beta_seats(): void
    {
        [$owner, $workspace] = $this->workspaceUser('owner');

        for ($seat = 2; $seat <= 5; $seat++) {
            $workspace->invitations()->create([
                'invited_by_user_id' => $owner->id,
                'email' => "seat{$seat}@agency.com",
                'token_hash' => hash('sha256', Str::random(48)),
                'expires_at' => now()->addDays(7),
            ]);
        }

        Livewire::actingAs($owner)
            ->test(TeamIndex::class)
            ->set('email', 'overflow@agency.com')
            ->call('invite')
            ->assertHasErrors(['email' => 'All five beta seats are already assigned or reserved.']);
    }

    public function test_an_existing_user_can_accept_an_invitation_for_their_email(): void
    {
        [$owner, $workspace] = $this->workspaceUser('owner');
        $member = User::factory()->create(['email' => 'buyer@agency.com']);
        [$token, $invitation] = $this->invitation($workspace, $owner, $member->email);

        $this->actingAs($member)
            ->get(route('invitations.accept', ['token' => $token]))
            ->assertRedirect(route('team.index'));

        $this->assertTrue($workspace->users()->whereKey($member->id)->exists());
        $this->assertNotNull($invitation->fresh()->accepted_at);
        $this->assertSame($workspace->id, session('current_workspace_id'));
    }

    public function test_an_invited_new_user_joins_without_creating_another_workspace(): void
    {
        $this->startSession();
        [$owner, $workspace] = $this->workspaceUser('owner');
        [$token, $invitation] = $this->invitation($workspace, $owner, 'new@agency.com');

        Livewire::withQueryParams(['invite' => $token])
            ->test(Register::class)
            ->assertSet('invitedWorkspaceName', $workspace->name)
            ->assertSet('email', 'new@agency.com')
            ->set('name', 'New Buyer')
            ->set('password', 'campaign123')
            ->set('password_confirmation', 'campaign123')
            ->call('register')
            ->assertHasNoErrors()
            ->assertRedirect(route('campaign-packs.index'));

        $user = User::where('email', 'new@agency.com')->firstOrFail();
        $this->assertDatabaseCount('workspaces', 1);
        $this->assertSame('member', $user->workspaces()->firstOrFail()->pivot->role);
        $this->assertNotNull($invitation->fresh()->accepted_at);
    }

    public function test_an_invitation_cannot_be_accepted_by_a_different_email(): void
    {
        [$owner, $workspace] = $this->workspaceUser('owner');
        [$token] = $this->invitation($workspace, $owner, 'buyer@agency.com');
        $other = User::factory()->create(['email' => 'other@agency.com']);

        $this->actingAs($other)
            ->get(route('invitations.accept', ['token' => $token]))
            ->assertForbidden();
    }

    public function test_a_member_can_switch_between_their_workspaces(): void
    {
        [$user, $firstWorkspace] = $this->workspaceUser('owner');
        $secondWorkspace = Workspace::create(['name' => 'Second Agency']);
        $secondWorkspace->users()->attach($user, ['role' => 'owner']);

        $this->actingAs($user)
            ->from(route('team.index'))
            ->post(route('workspaces.select', $secondWorkspace))
            ->assertRedirect(route('team.index'))
            ->assertSessionHas('current_workspace_id', $secondWorkspace->id);

        $this->assertNotSame($firstWorkspace->id, session('current_workspace_id'));
    }

    public function test_a_user_cannot_select_an_unrelated_workspace(): void
    {
        [$user] = $this->workspaceUser('owner');
        $otherWorkspace = Workspace::create(['name' => 'Other Agency']);

        $this->actingAs($user)
            ->post(route('workspaces.select', $otherWorkspace))
            ->assertNotFound();
    }

    public function test_an_owner_can_rename_a_workspace_with_an_audit_event(): void
    {
        [$owner, $workspace] = $this->workspaceUser('owner');

        Livewire::actingAs($owner)
            ->test(WorkspaceSettings::class)
            ->set('name', 'Northstar Performance')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('workspaces', ['id' => $workspace->id, 'name' => 'Northstar Performance']);
        $this->assertDatabaseHas('workspace_audit_events', [
            'workspace_id' => $workspace->id,
            'actor_user_id' => $owner->id,
            'event' => 'workspace_renamed',
        ]);
    }

    public function test_a_member_cannot_change_workspace_settings(): void
    {
        [$member] = $this->workspaceUser('member');

        Livewire::actingAs($member)
            ->test(WorkspaceSettings::class)
            ->set('name', 'Nope')
            ->call('save')
            ->assertForbidden();
    }

    public function test_an_allow_listed_concierge_can_make_an_idempotent_credit_adjustment_with_a_reason(): void
    {
        $concierge = User::factory()->create(['email' => 'support@marketingowl.ai']);
        $workspace = Workspace::create(['name' => 'Customer Workspace']);
        $workspace->users()->attach($concierge, ['role' => 'owner']);
        config()->set('campaigns.concierge_emails', [$concierge->email]);
        $key = (string) Str::uuid();

        $component = Livewire::actingAs($concierge)
            ->test(ConciergeIndex::class)
            ->set('workspaceId', $workspace->id)
            ->set('adjustmentAmount', 4)
            ->set('adjustmentReason', 'Restore credits after provider failure.')
            ->set('adjustmentKey', $key)
            ->call('adjustCredits')
            ->assertHasNoErrors();

        $component
            ->set('adjustmentAmount', 4)
            ->set('adjustmentReason', 'Restore credits after provider failure.')
            ->set('adjustmentKey', $key)
            ->call('adjustCredits');

        $this->assertSame(1, WorkspaceCredit::query()->where('idempotency_key', $key)->count());
        $this->assertDatabaseHas('workspace_audit_events', [
            'workspace_id' => $workspace->id,
            'actor_user_id' => $concierge->id,
            'event' => 'credits_adjusted',
        ]);
    }

    public function test_concierge_access_is_hidden_without_an_explicit_allow_list_entry(): void
    {
        [$user] = $this->workspaceUser('owner');
        config()->set('campaigns.concierge_emails', []);

        Livewire::actingAs($user)
            ->test(ConciergeIndex::class)
            ->assertNotFound();
    }

    public function test_an_allow_listed_concierge_can_retry_failed_jobs_and_cancel_queued_jobs(): void
    {
        Queue::fake();
        $concierge = User::factory()->create(['email' => 'support@marketingowl.ai']);
        $workspace = Workspace::create(['name' => 'Customer Workspace']);
        config()->set('campaigns.concierge_emails', [$concierge->email]);
        $failedJob = $this->generationJob($workspace, 'failed');
        $queuedJob = $this->generationJob($workspace, 'queued');

        Livewire::actingAs($concierge)
            ->test(ConciergeIndex::class)
            ->set('workspaceId', $workspace->id)
            ->call('retryJob', $failedJob->id)
            ->call('cancelJob', $queuedJob->id);

        $this->assertDatabaseHas('campaign_generation_jobs', ['id' => $failedJob->id, 'status' => 'queued']);
        $this->assertDatabaseHas('campaign_generation_jobs', ['id' => $queuedJob->id, 'status' => 'cancelled']);
        $this->assertDatabaseHas('workspace_audit_events', ['workspace_id' => $workspace->id, 'event' => 'job_retry_requested']);
        $this->assertDatabaseHas('workspace_audit_events', ['workspace_id' => $workspace->id, 'event' => 'job_cancelled']);
        Queue::assertPushed(GenerateCampaignPack::class, fn (GenerateCampaignPack $job): bool => $job->generationJobId === $failedJob->id);
    }

    private function workspaceUser(string $role): array
    {
        $user = User::factory()->create();
        $workspace = Workspace::create(['name' => 'Agency Workspace']);
        $workspace->users()->attach($user, ['role' => $role]);

        return [$user, $workspace];
    }

    private function invitation(Workspace $workspace, User $owner, string $email): array
    {
        $token = Str::random(48);
        $invitation = $workspace->invitations()->create([
            'invited_by_user_id' => $owner->id,
            'email' => $email,
            'token_hash' => hash('sha256', $token),
            'expires_at' => now()->addDays(7),
        ]);

        return [$token, $invitation];
    }

    private function generationJob(Workspace $workspace, string $status): CampaignGenerationJob
    {
        $brand = Brand::create(['workspace_id' => $workspace->id, 'name' => 'Test Brand']);
        $product = Product::create(['brand_id' => $brand->id, 'name' => 'Test Product']);
        $source = SourceSnapshot::create([
            'product_id' => $product->id,
            'url' => 'https://example.com/product',
            'content_hash' => Str::random(64),
        ]);
        $pack = CampaignPack::create([
            'product_id' => $product->id,
            'source_snapshot_id' => $source->id,
            'name' => 'Test pack',
            'status' => $status,
        ]);

        return CampaignGenerationJob::create([
            'workspace_id' => $workspace->id,
            'campaign_pack_id' => $pack->id,
            'source_snapshot_id' => $source->id,
            'status' => $status,
            'phase' => $status === 'failed' ? 'failed' : 'waiting',
        ]);
    }
}
