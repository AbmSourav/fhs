<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Customer;
use App\Models\Expense;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * What each kind of user may do.
 *
 * Three levels, each a subset of the one above:
 *
 *  - admin    — everything, including creating and removing accounts
 *  - founder  — everything except that
 *  - investor — reads every page, changes nothing
 */
class RoleAccessTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create();
        config(['app.admin_emails' => [strtolower($this->admin->email)]]);
    }

    private function userWith(UserRole $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);
        $user->save();

        return $user;
    }

    public function test_a_founder_reaches_the_business_pages(): void
    {
        $this->actingAs($this->userWith(UserRole::Founder))
            ->get('/orders')
            ->assertOk();
    }

    public function test_an_investor_reaches_the_business_pages(): void
    {
        // The whole point of the role: they see the same figures a founder
        // does, they simply cannot change any of them.
        $this->actingAs($this->userWith(UserRole::Investor))
            ->get('/statistics')
            ->assertOk();
    }

    public function test_a_user_with_no_role_reaches_nothing(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/orders')
            ->assertForbidden();
    }

    public function test_a_founder_may_write(): void
    {
        $this->actingAs($this->userWith(UserRole::Founder))
            ->post('/brands', ['name' => 'Jamuna'])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('brands', ['name' => 'Jamuna']);
    }

    public function test_an_investor_may_not_write(): void
    {
        $this->actingAs($this->userWith(UserRole::Investor))
            ->post('/brands', ['name' => 'Jamuna'])
            ->assertForbidden();

        $this->assertDatabaseMissing('brands', ['name' => 'Jamuna']);
    }

    public function test_an_investor_is_refused_every_write_verb(): void
    {
        $investor = $this->userWith(UserRole::Investor);

        // Against records that exist, so a 404 from model binding cannot be
        // mistaken for the refusal being tested.
        $customer = Customer::create(['name' => 'Rahim', 'mobile_number' => '01700000001']);
        $expense = Expense::create([
            'category'       => 'rent',
            'description'    => 'Shop rent',
            'amount'         => 5000,
            'payment_method' => 'cash',
            'spent_at'       => now(),
            'recorded_by'    => $this->admin->id,
        ]);

        // Read off the verb rather than the route name, so each of these is
        // refused wherever it appears.
        $this->actingAs($investor)->post('/orders', [])->assertForbidden();
        $this->actingAs($investor)->patch("/customers/update/{$customer->id}", [])->assertForbidden();
        $this->actingAs($investor)->delete("/expenses/{$expense->id}")->assertForbidden();

        // Still there: the refusal happened before anything was touched.
        $this->assertDatabaseHas('expenses', ['id' => $expense->id, 'deleted_at' => null]);
    }

    public function test_an_investor_cannot_open_a_form_that_only_writes(): void
    {
        $investor = $this->userWith(UserRole::Investor);

        // Refused at the page rather than on submit. These are GETs, so the
        // verb-based middleware would let them through — offering a form that
        // cannot be submitted wastes the reader's time and reads as a fault.
        foreach (['/catalogue/setup', '/orders/add', '/expenses/add', '/inventories/add'] as $form) {
            $this->actingAs($investor)->get($form)->assertForbidden();
        }
    }

    public function test_a_founder_can_open_those_forms(): void
    {
        $founder = $this->userWith(UserRole::Founder);

        foreach (['/catalogue/setup', '/orders/add', '/expenses/add', '/inventories/add'] as $form) {
            $this->actingAs($founder)->get($form)->assertOk();
        }
    }

    public function test_an_investor_still_reads_the_listing_pages(): void
    {
        $investor = $this->userWith(UserRole::Investor);

        // The point of the split: the list is theirs, the form is not.
        foreach (['/catalogue', '/orders', '/expenses', '/inventories', '/customers', '/crm'] as $page) {
            $this->actingAs($investor)->get($page)->assertOk();
        }
    }

    public function test_an_admin_may_write(): void
    {
        $this->actingAs($this->admin)
            ->post('/brands', ['name' => 'Omera'])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('brands', ['name' => 'Omera']);
    }

    public function test_only_an_admin_manages_users(): void
    {
        $payload = [
            'name'                  => 'Karim',
            'email'                 => 'karim@example.com',
            'password'              => 'correct-horse-battery',
            'password_confirmation' => 'correct-horse-battery',
            'role'                  => 'founder',
        ];

        // A founder runs the business but cannot grant access: doing so would
        // let them make themselves an equal, or lock the administrator out.
        $this->actingAs($this->userWith(UserRole::Founder))
            ->get(route('users.index'))
            ->assertForbidden();

        $this->actingAs($this->userWith(UserRole::Founder))
            ->post(route('users.store'), $payload)
            ->assertForbidden();

        $this->actingAs($this->userWith(UserRole::Investor))
            ->get(route('users.index'))
            ->assertForbidden();

        $this->actingAs($this->admin)
            ->get(route('users.index'))
            ->assertOk();
    }

    public function test_the_frontend_is_told_what_each_user_may_do(): void
    {
        // So a button the server would refuse is never offered.
        $this->actingAs($this->userWith(UserRole::Investor))
            ->get('/dashboard')
            ->assertInertia(fn ($page) => $page
                ->where('auth.canWrite', false)
                ->where('auth.canManageUsers', false));

        $this->actingAs($this->userWith(UserRole::Founder))
            ->get('/dashboard')
            ->assertInertia(fn ($page) => $page
                ->where('auth.canWrite', true)
                ->where('auth.canManageUsers', false));

        $this->actingAs($this->admin)
            ->get('/dashboard')
            ->assertInertia(fn ($page) => $page
                ->where('auth.canWrite', true)
                ->where('auth.canManageUsers', true));
    }
}
