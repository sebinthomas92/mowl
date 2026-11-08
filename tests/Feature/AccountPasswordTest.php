<?php

namespace Tests\Feature;

use App\Livewire\AccountSettings;
use App\Livewire\Auth\ForgotPassword;
use App\Livewire\Auth\ResetPassword;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Auth\Notifications\ResetPassword as ResetPasswordNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Livewire\Livewire;
use Tests\TestCase;

class AccountPasswordTest extends TestCase
{
    use RefreshDatabase;

    private function member(string $role = 'owner', string $password = 'old-password'): User
    {
        $user = User::factory()->create(['password' => $password]);
        $workspace = Workspace::create(['name' => 'Northstar Media']);
        $workspace->users()->attach($user, ['role' => $role]);

        return $user;
    }

    public function test_account_page_opens_for_a_member_and_is_linked_from_the_account_menu(): void
    {
        $user = $this->member();

        $this->actingAs($user)->get(route('account.edit'))->assertOk()->assertSee('Change password')->assertSee($user->email);
        $this->actingAs($user)->get(route('products.index'))->assertOk()->assertSee(route('account.edit'));
    }

    public function test_a_client_can_open_the_account_page(): void
    {
        $client = $this->member('client');

        $this->actingAs($client)->get(route('account.edit'))->assertOk()->assertSee('Change password')->assertSee('Back to your work');
    }

    public function test_changing_the_password_needs_the_current_one(): void
    {
        $user = $this->member();

        Livewire::actingAs($user)->test(AccountSettings::class)
            ->set('current_password', 'wrong-password')
            ->set('password', 'brand-new-password')
            ->set('password_confirmation', 'brand-new-password')
            ->call('updatePassword')
            ->assertHasErrors('current_password');

        $this->assertTrue(Hash::check('old-password', $user->fresh()->password));
    }

    public function test_new_password_is_validated(): void
    {
        $user = $this->member();

        Livewire::actingAs($user)->test(AccountSettings::class)
            ->set('current_password', 'old-password')
            ->set('password', 'short')
            ->set('password_confirmation', 'short')
            ->call('updatePassword')
            ->assertHasErrors('password');

        Livewire::actingAs($user)->test(AccountSettings::class)
            ->set('current_password', 'old-password')
            ->set('password', 'brand-new-password')
            ->set('password_confirmation', 'different-password')
            ->call('updatePassword')
            ->assertHasErrors(['password' => 'confirmed']);

        Livewire::actingAs($user)->test(AccountSettings::class)
            ->set('current_password', 'old-password')
            ->set('password', 'old-password')
            ->set('password_confirmation', 'old-password')
            ->call('updatePassword')
            ->assertHasErrors(['password' => 'different']);
    }

    public function test_password_change_saves_and_signs_out_other_sessions(): void
    {
        config(['session.driver' => 'database']);
        $user = $this->member();
        $other = $this->member();
        DB::table('sessions')->insert([
            ['id' => 'other-device', 'user_id' => $user->id, 'payload' => '', 'last_activity' => time()],
            ['id' => 'someone-else', 'user_id' => $other->id, 'payload' => '', 'last_activity' => time()],
        ]);
        $oldRemember = $user->remember_token;

        Livewire::actingAs($user)->test(AccountSettings::class)
            ->set('current_password', 'old-password')
            ->set('password', 'brand-new-password')
            ->set('password_confirmation', 'brand-new-password')
            ->call('updatePassword')
            ->assertHasNoErrors()
            ->assertSet('current_password', '')
            ->assertSet('password', '');

        $user->refresh();
        $this->assertTrue(Hash::check('brand-new-password', $user->password));
        $this->assertNotSame($oldRemember, $user->remember_token);
        $this->assertDatabaseMissing('sessions', ['id' => 'other-device']);
        $this->assertDatabaseHas('sessions', ['id' => 'someone-else']);
    }

    public function test_wrong_current_password_is_rate_limited(): void
    {
        $user = $this->member();
        $component = Livewire::actingAs($user)->test(AccountSettings::class)
            ->set('password', 'brand-new-password')
            ->set('password_confirmation', 'brand-new-password');

        foreach (range(1, 5) as $attempt) {
            $component->set('current_password', 'wrong-'.$attempt)->call('updatePassword');
        }

        $component->set('current_password', 'old-password')->call('updatePassword')->assertHasErrors('current_password');
        $this->assertTrue(Hash::check('old-password', $user->fresh()->password));
    }

    public function test_forgot_password_is_hidden_without_a_real_mailer(): void
    {
        config(['mail.default' => 'log']);

        $this->get('/login')->assertOk()->assertDontSee('Forgot password?');
        $this->get(route('password.request'))->assertOk()->assertSee('Reset by email is not on yet.');
        $this->get(route('password.reset', 'some-token'))->assertNotFound();
    }

    public function test_forgot_password_sends_a_reset_link_without_revealing_accounts(): void
    {
        config(['mail.default' => 'smtp']);
        Notification::fake();
        $user = $this->member();

        $this->get('/login')->assertOk()->assertSee('Forgot password?');

        Livewire::test(ForgotPassword::class)->set('email', $user->email)->call('sendLink')->assertSet('sent', true);
        Notification::assertSentTo($user, ResetPasswordNotification::class);

        Livewire::test(ForgotPassword::class)->set('email', 'nobody@example.com')->call('sendLink')->assertHasNoErrors()->assertSet('sent', true);
    }

    public function test_reset_link_sets_a_new_password(): void
    {
        config(['mail.default' => 'smtp', 'session.driver' => 'database']);
        $user = $this->member();
        DB::table('sessions')->insert(['id' => 'stolen', 'user_id' => $user->id, 'payload' => '', 'last_activity' => time()]);
        $token = Password::createToken($user);

        $this->get(route('password.reset', ['token' => $token, 'email' => $user->email]))->assertOk()->assertSee('Choose a new password.');

        Livewire::withQueryParams(['email' => $user->email])->test(ResetPassword::class, ['token' => $token])
            ->assertSet('email', $user->email)
            ->set('password', 'reset-password-1')
            ->set('password_confirmation', 'reset-password-1')
            ->call('resetPassword')
            ->assertHasNoErrors()
            ->assertRedirect(route('login'));

        $this->assertTrue(Hash::check('reset-password-1', $user->fresh()->password));
        $this->assertDatabaseMissing('sessions', ['id' => 'stolen']);

        Livewire::withQueryParams(['email' => $user->email])->test(ResetPassword::class, ['token' => $token])
            ->set('password', 'reset-password-2')
            ->set('password_confirmation', 'reset-password-2')
            ->call('resetPassword')
            ->assertHasErrors('email');
    }
}
