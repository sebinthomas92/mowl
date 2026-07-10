<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_authentication_pages_are_available_to_guests(): void
    {
        $this->get('/login')->assertOk()->assertSee('Enter the workspace.');
        $this->get('/register')->assertOk()->assertSee('CREATE YOUR WORKSPACE');
    }

    public function test_workspace_membership_records_the_owner_role(): void
    {
        $user = User::factory()->create();
        $workspace = Workspace::create(['name' => 'Northstar Media']);
        $workspace->users()->attach($user, ['role' => 'owner']);

        $this->assertSame('owner', $user->workspaces()->firstOrFail()->pivot->role);
    }

    public function test_workspace_routes_require_authentication(): void
    {
        $this->get('/brands')->assertRedirect('/login');
        $this->get('/products')->assertRedirect('/login');
        $this->get('/campaign-packs')->assertRedirect('/login');
    }
}
