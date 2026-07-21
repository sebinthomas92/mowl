<?php

namespace Tests\Feature;

use App\Livewire\Auth\ForgotPassword;
use App\Livewire\Auth\Login;
use App\Livewire\Auth\Register;
use App\Livewire\Auth\ResetPassword as ResetPasswordComponent;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Auth\Notifications\ResetPassword as ResetPasswordNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Livewire\Livewire;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_authentication_pages_are_available_to_guests(): void
    {
        $this->get('/login')->assertOk()->assertSee('Enter the workspace.');
        $this->get('/register')->assertOk()->assertSee('CREATE YOUR WORKSPACE');
        $this->get('/forgot-password')->assertOk()->assertSee('Recover your account.');
        $this->get('/reset-password/test-token?email=owner%40example.com')->assertOk()->assertSee('Choose a new password.');
    }

    public function test_a_user_can_request_a_password_reset_link_without_account_enumeration(): void
    {
        Notification::fake();
        $user = User::factory()->create(['email' => 'owner@example.com']);

        Livewire::test(ForgotPassword::class)
            ->set('email', $user->email)
            ->call('sendResetLink')
            ->assertSee('If an account exists for that email, a reset link has been sent.');

        Notification::assertSentTo($user, ResetPasswordNotification::class);

        Livewire::test(ForgotPassword::class)
            ->set('email', 'missing@example.com')
            ->call('sendResetLink')
            ->assertSee('If an account exists for that email, a reset link has been sent.');
    }

    public function test_a_user_can_reset_their_password_with_a_valid_token(): void
    {
        $user = User::factory()->create(['email' => 'owner@example.com']);
        $token = Password::createToken($user);

        Livewire::test(ResetPasswordComponent::class, ['token' => $token])
            ->set('email', $user->email)
            ->set('password', 'new-secure-password')
            ->set('password_confirmation', 'new-secure-password')
            ->call('resetPassword')
            ->assertHasNoErrors()
            ->assertRedirect(route('login'));

        $this->assertTrue(Hash::check('new-secure-password', $user->fresh()->password));
    }

    public function test_a_password_reset_token_cannot_be_reused(): void
    {
        $user = User::factory()->create(['email' => 'owner@example.com']);
        $token = Password::createToken($user);

        Livewire::test(ResetPasswordComponent::class, ['token' => $token])
            ->set('email', $user->email)
            ->set('password', 'first-secure-password')
            ->set('password_confirmation', 'first-secure-password')
            ->call('resetPassword')
            ->assertRedirect(route('login'));

        Livewire::test(ResetPasswordComponent::class, ['token' => $token])
            ->set('email', $user->email)
            ->set('password', 'second-secure-password')
            ->set('password_confirmation', 'second-secure-password')
            ->call('resetPassword')
            ->assertHasErrors(['email']);

        $this->assertTrue(Hash::check('first-secure-password', $user->fresh()->password));
    }

    public function test_a_password_reset_token_is_bound_to_the_requested_email(): void
    {
        $user = User::factory()->create(['email' => 'owner@example.com']);
        $token = Password::createToken($user);

        Livewire::test(ResetPasswordComponent::class, ['token' => $token])
            ->set('email', 'other@example.com')
            ->set('password', 'new-secure-password')
            ->set('password_confirmation', 'new-secure-password')
            ->call('resetPassword')
            ->assertHasErrors(['email']);

        $this->assertFalse(Hash::check('new-secure-password', $user->fresh()->password));
    }

    public function test_an_expired_password_reset_token_is_rejected(): void
    {
        $user = User::factory()->create(['email' => 'owner@example.com']);
        $token = Password::createToken($user);
        $this->travel(61)->minutes();

        Livewire::test(ResetPasswordComponent::class, ['token' => $token])
            ->set('email', $user->email)
            ->set('password', 'new-secure-password')
            ->set('password_confirmation', 'new-secure-password')
            ->call('resetPassword')
            ->assertHasErrors(['email']);

        $this->assertFalse(Hash::check('new-secure-password', $user->fresh()->password));
    }

    public function test_a_tampered_password_reset_token_is_rejected(): void
    {
        $user = User::factory()->create(['email' => 'owner@example.com']);
        $token = Password::createToken($user).'tampered';

        Livewire::test(ResetPasswordComponent::class, ['token' => $token])
            ->set('email', $user->email)
            ->set('password', 'new-secure-password')
            ->set('password_confirmation', 'new-secure-password')
            ->call('resetPassword')
            ->assertHasErrors(['email']);

        $this->assertFalse(Hash::check('new-secure-password', $user->fresh()->password));
    }

    public function test_login_attempts_are_throttled_by_email_and_ip(): void
    {
        $component = Livewire::test(Login::class)
            ->set('email', 'owner@example.com')
            ->set('password', 'incorrect-password');

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $component->call('login')->assertHasErrors(['email']);
        }

        $component->call('login')->assertSee('Too many login attempts. Please try again');
    }

    public function test_registration_attempts_are_throttled_by_ip(): void
    {
        $component = Livewire::test(Register::class);

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $component->call('register')->assertHasErrors();
        }

        $component->call('register')->assertSee('Too many registration attempts. Please try again');
    }

    public function test_password_reset_requests_are_throttled_by_email_and_ip(): void
    {
        Notification::fake();
        $component = Livewire::test(ForgotPassword::class)->set('email', 'owner@example.com');

        for ($attempt = 0; $attempt < 3; $attempt++) {
            $component->call('sendResetLink');
        }

        $component->call('sendResetLink')->assertSee('Too many reset requests. Please try again');
    }

    public function test_web_responses_include_security_headers(): void
    {
        $this->get('/login')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('X-Frame-Options', 'DENY')
            ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
            ->assertHeader('Permissions-Policy', 'camera=(), microphone=(), geolocation=()')
            ->assertHeader('Content-Security-Policy')
            ->assertHeaderMissing('Strict-Transport-Security');

        $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.1'])
            ->withHeaders(['X-Forwarded-Proto' => 'https'])
            ->get('/login')
            ->assertHeader('Strict-Transport-Security', 'max-age=31536000');
    }

    public function test_forwarded_https_is_used_for_livewire_assets(): void
    {
        $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.1'])
            ->withHeaders([
                'X-Forwarded-Host' => 'app.marketingowl.ai',
                'X-Forwarded-Proto' => 'https',
                'X-Forwarded-Port' => '443',
            ])
            ->get('/register')
            ->assertOk()
            ->assertSee('https://app.marketingowl.ai/livewire-', false);
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
