<?php

namespace Tests\Feature;

use App\Livewire\Auth\Register;
use App\Livewire\TeamIndex;
use App\Mail\WorkspaceInvitationMail;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceInvitation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

class TeamManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_owner_can_create_a_hashed_seven_day_invitation(): void
    {
        Mail::fake();
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
        Mail::assertSent(WorkspaceInvitationMail::class, fn (WorkspaceInvitationMail $mail): bool => $mail->hasTo('buyer@agency.com'));
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
}
