<?php

namespace Tests\Feature\Settings;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Creating and removing accounts.
 *
 * Founders and investors are given credentials out of band, so this is the only
 * way an account comes into being — there is no self-registration.
 */
class UserManagementTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create();
        config(['app.admin_emails' => [strtolower($this->admin->email)]]);
    }

    /** @return array<string, string> */
    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'name'                  => 'Rahim Uddin',
            'email'                 => 'rahim@example.com',
            'password'              => 'correct-horse-battery',
            'password_confirmation' => 'correct-horse-battery',
            'role'                  => 'founder',
        ], $overrides);
    }

    public function test_an_admin_can_create_a_user(): void
    {
        $this->actingAs($this->admin)
            ->post(route('users.store'), $this->validPayload())
            ->assertRedirect(route('users.index'));

        $user = User::where('email', 'rahim@example.com')->first();

        $this->assertNotNull($user);
        $this->assertSame('Rahim Uddin', $user->name);
        $this->assertSame(UserRole::Founder, $user->role());
    }

    public function test_a_new_account_is_verified_on_creation(): void
    {
        $this->actingAs($this->admin)->post(route('users.store'), $this->validPayload());

        // An administrator entering these by hand has confirmed the address,
        // and there is no inbox to send to for credentials handed over in
        // person.
        $this->assertNotNull(User::where('email', 'rahim@example.com')->first()->email_verified_at);
    }

    public function test_the_new_user_can_sign_in_with_the_password_given(): void
    {
        $this->actingAs($this->admin)->post(route('users.store'), $this->validPayload());

        $this->post('/logout');

        $this->post('/login', [
            'email'    => 'rahim@example.com',
            'password' => 'correct-horse-battery',
        ])->assertRedirect();

        $this->assertAuthenticated();
    }

    public function test_the_role_is_stored_in_the_permission_column(): void
    {
        $this->actingAs($this->admin)->post(route('users.store'), $this->validPayload(['role' => 'investor']));

        $user = User::where('email', 'rahim@example.com')->first();

        // Held in the JSON column rather than one of its own, so adding a role
        // stays a code change with no migration.
        $this->assertSame(['role' => 'investor'], $user->permission);
        $this->assertSame(UserRole::Investor, $user->role());
    }

    public function test_a_role_outside_the_enum_is_rejected(): void
    {
        // The gap this closes: 'admin' is not a role anyone can be given, since
        // administrators are identified by config.
        $this->actingAs($this->admin)
            ->post(route('users.store'), $this->validPayload(['role' => 'admin']))
            ->assertSessionHasErrors('role');

        $this->assertDatabaseCount('users', 1);
    }

    public function test_permission_cannot_be_set_straight_from_the_request(): void
    {
        $this->actingAs($this->admin)->post(route('users.store'), $this->validPayload([
            'permission' => ['role' => 'investor', 'can_do_anything' => true],
        ]));

        $user = User::where('email', 'rahim@example.com')->first();

        // Only the validated role survives: `permission` is not mass-assignable,
        // so a crafted payload cannot grant itself capabilities.
        $this->assertSame(['role' => 'founder'], $user->permission);
    }

    public function test_an_email_already_in_use_is_rejected(): void
    {
        User::factory()->create(['email' => 'rahim@example.com']);

        $this->actingAs($this->admin)
            ->post(route('users.store'), $this->validPayload())
            ->assertSessionHasErrors('email');
    }

    public function test_a_mismatched_confirmation_is_rejected(): void
    {
        $this->actingAs($this->admin)
            ->post(route('users.store'), $this->validPayload(['password_confirmation' => 'something-else']))
            ->assertSessionHasErrors('password');

        $this->assertDatabaseCount('users', 1);
    }

    public function test_an_admin_can_delete_a_user(): void
    {
        $user = User::factory()->create();

        $this->actingAs($this->admin)
            ->delete(route('users.destroy', $user->id))
            ->assertRedirect(route('users.index'));

        $this->assertDatabaseMissing('users', ['id' => $user->id]);
    }

    public function test_an_admin_cannot_delete_their_own_account(): void
    {
        // Locking yourself out of an internal tool has no way back.
        $this->actingAs($this->admin)
            ->delete(route('users.destroy', $this->admin->id))
            ->assertSessionHas('error');

        $this->assertDatabaseHas('users', ['id' => $this->admin->id]);
    }

    public function test_an_administrator_account_cannot_be_deleted(): void
    {
        $otherAdmin = User::factory()->create();
        config(['app.admin_emails' => [
            strtolower($this->admin->email),
            strtolower($otherAdmin->email),
        ]]);

        // Their access comes from config, so removing the row would strand it
        // rather than revoke it.
        $this->actingAs($this->admin)
            ->delete(route('users.destroy', $otherAdmin->id))
            ->assertSessionHas('error');

        $this->assertDatabaseHas('users', ['id' => $otherAdmin->id]);
    }

    public function test_the_page_lists_everyone(): void
    {
        User::factory()->create(['name' => 'Karim']);

        $this->actingAs($this->admin)
            ->get(route('users.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('settings/users')
                ->has('users', 2)
                ->has('roles', 2)
                ->where('currentUserId', $this->admin->id));
    }

    public function test_managing_users_is_admin_only(): void
    {
        $outsider = User::factory()->create();

        $this->actingAs($outsider)->get(route('users.index'))->assertForbidden();
        $this->actingAs($outsider)->post(route('users.store'), $this->validPayload())->assertForbidden();
        $this->actingAs($outsider)->delete(route('users.destroy', $this->admin->id))->assertForbidden();
    }

    public function test_managing_users_requires_signing_in(): void
    {
        $this->get(route('users.index'))->assertRedirect('/login');
    }
}
