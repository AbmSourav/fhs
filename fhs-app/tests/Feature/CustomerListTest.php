<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\Catalogue;
use App\Models\Customer;
use App\Models\User;
use App\Services\CustomerService;
use App\Services\InventoryService;
use App\Services\OrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The customer book, ranked by repeat custom.
 *
 * Every figure is derived from orders and payments, so these assert the
 * aggregates rather than any stored column — there is none to store.
 */
class CustomerListTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private CustomerService $customers;

    private OrderService $orders;

    private Catalogue $cylinder;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();

        // Customer routes are behind the `admin` gate, which reads a config
        // list of email addresses rather than a role column.
        config(['app.admin_emails' => [strtolower($this->user->email)]]);

        $this->customers = app(CustomerService::class);
        $this->orders = app(OrderService::class);

        $brand = Brand::create(['name' => 'Jamuna', 'slug' => 'jamuna']);
        $this->cylinder = Catalogue::create([
            'type'          => 'lpg_cylinder',
            'brand_id'      => $brand->id,
            'weight'        => 12.5,
            'is_gas'        => true,
            'is_returnable' => true,
        ]);

        app(InventoryService::class)->record([
            'catalogue_id'    => $this->cylinder->id,
            'purchased_at'    => now()->subDays(30)->toDateString(),
            'filled_quantity' => 500,
            'empty_quantity'  => 500,
            'shell_unit_cost' => 900,
            'gas_unit_cost'   => 340,
        ], $this->user->id);
    }

    /** Record a sale for one customer, returning nothing of interest. */
    private function sellTo(string $mobile, string $name, float $price, array $overrides = []): void
    {
        $this->orders->record([
            'mobile_number' => $mobile,
            'customer_name' => $name,
            'occurred_at'   => now()->toDateString(),
            'items'         => [[
                'catalogue_id'     => $this->cylinder->id,
                'transaction_type' => 'swap',
                'quantity'         => 1,
                'unit_price'       => $price,
            ]],
            'is_paid' => true,
            ...$overrides,
        ], $this->user->id);
    }

    /** Collect money against a customer's most recent sale. */
    private function settle(Customer $customer, float $amount, Carbon $at): void
    {
        $order = $customer->orders()->latest('id')->first();

        $this->orders->settle($order, [
            'amount'  => $amount,
            'method'  => 'cash',
            'paid_at' => $at->toDateTimeString(),
        ], $this->user->id);
    }

    public function test_customers_are_ranked_by_how_often_they_buy(): void
    {
        // Three orders.
        foreach (range(1, 3) as $ignored) {
            $this->sellTo('01700000001', 'Regular', 1400);
        }

        $this->sellTo('01700000002', 'Occasional', 1400);
        $this->sellTo('01700000002', 'Occasional', 1400);

        $this->sellTo('01700000003', 'One Timer', 1400);

        $names = collect($this->customers->paginate()->items())->pluck('name')->all();

        $this->assertSame(['Regular', 'Occasional', 'One Timer'], $names);
    }

    public function test_a_customer_order_count_and_lifetime_spend_are_totalled(): void
    {
        $this->sellTo('01700000001', 'Rahim', 1400);
        $this->sellTo('01700000001', 'Rahim', 1600);

        $row = collect($this->customers->paginate()->items())->firstWhere('name', 'Rahim');

        $this->assertSame(2, $row['order_count']);
        $this->assertSame(3000.0, $row['total_spent']);
    }

    public function test_the_outstanding_balance_survives_instalment_payments(): void
    {
        // 3000 billed across two orders, 1000 received in total.
        $this->sellTo('01700000001', 'Rahim', 1400, ['is_paid' => false, 'amount_paid' => 600]);
        $this->sellTo('01700000001', 'Rahim', 1600, ['is_paid' => false, 'amount_paid' => 400]);

        $row = collect($this->customers->paginate()->items())->firstWhere('name', 'Rahim');

        // Joining orders to payments would repeat each order total once per
        // payment row and report 4000 owed instead of 2000.
        $this->assertSame(3000.0, $row['total_spent']);
        $this->assertSame(2000.0, $row['due_amount']);
    }

    public function test_a_settled_customer_owes_nothing(): void
    {
        $this->sellTo('01700000001', 'Rahim', 1400);

        $row = collect($this->customers->paginate()->items())->firstWhere('name', 'Rahim');

        $this->assertSame(0.0, $row['due_amount']);
    }

    public function test_the_last_order_date_is_the_most_recent_one(): void
    {
        $this->sellTo('01700000001', 'Rahim', 1400, ['occurred_at' => now()->subDays(10)->toDateString()]);
        $this->sellTo('01700000001', 'Rahim', 1400, ['occurred_at' => now()->subDays(2)->toDateString()]);

        $row = collect($this->customers->paginate()->items())->firstWhere('name', 'Rahim');

        $this->assertSame(
            now()->subDays(2)->toDateString(),
            $row['last_ordered_at']->toDateString(),
        );
    }

    public function test_a_customer_who_never_ordered_still_appears(): void
    {
        Customer::create(['name' => 'Prospect', 'mobile_number' => '01700000009']);

        $row = collect($this->customers->paginate()->items())->firstWhere('name', 'Prospect');

        // Zero rather than null: an aggregate over no rows is still a figure.
        $this->assertSame(0, $row['order_count']);
        $this->assertSame(0.0, $row['total_spent']);
        $this->assertSame(0.0, $row['due_amount']);
        $this->assertNull($row['last_ordered_at']);
    }

    public function test_customers_paginate_ten_to_a_page(): void
    {
        foreach (range(1, 14) as $n) {
            $this->sellTo('017000000'.str_pad((string) $n, 2, '0', STR_PAD_LEFT), "Customer {$n}", 1400);
        }

        $page = $this->customers->paginate();

        $this->assertSame(14, $page->total());
        $this->assertCount(10, $page->items());
    }

    public function test_the_address_is_carried_for_delivery(): void
    {
        $this->sellTo('01700000001', 'Rahim', 1400, ['address' => '12 Green Road, Dhaka']);

        $row = collect($this->customers->paginate()->items())->firstWhere('name', 'Rahim');

        $this->assertSame('12 Green Road, Dhaka', $row['address']);
    }

    public function test_a_customer_lapses_after_forty_five_quiet_days(): void
    {
        $this->sellTo('01700000001', 'Recent', 1400, ['occurred_at' => now()->subDays(44)->toDateString()]);
        $this->sellTo('01700000002', 'Lapsed', 1400, ['occurred_at' => now()->subDays(46)->toDateString()]);

        $rows = collect($this->customers->paginate()->items());

        $this->assertFalse($rows->firstWhere('name', 'Recent')['has_lapsed']);
        $this->assertTrue($rows->firstWhere('name', 'Lapsed')['has_lapsed']);
    }

    public function test_a_customer_who_never_ordered_has_not_lapsed(): void
    {
        Customer::create(['name' => 'Prospect', 'mobile_number' => '01700000009']);

        $row = collect($this->customers->paginate()->items())->firstWhere('name', 'Prospect');

        // There is no buying rhythm to have fallen out of.
        $this->assertFalse($row['has_lapsed']);
    }

    public function test_a_recent_order_clears_an_earlier_quiet_spell(): void
    {
        $this->sellTo('01700000001', 'Rahim', 1400, ['occurred_at' => now()->subDays(90)->toDateString()]);
        $this->sellTo('01700000001', 'Rahim', 1400, ['occurred_at' => now()->subDays(3)->toDateString()]);

        $row = collect($this->customers->paginate()->items())->firstWhere('name', 'Rahim');

        // Only the most recent order counts, not the gap before it.
        $this->assertFalse($row['has_lapsed']);
    }

    public function test_a_customer_history_lists_their_orders_newest_first(): void
    {
        $this->sellTo('01700000001', 'Rahim', 1400, ['occurred_at' => now()->subDays(10)->toDateString()]);
        $this->sellTo('01700000001', 'Rahim', 1600, ['occurred_at' => now()->subDays(2)->toDateString()]);

        $customer = Customer::where('mobile_number', '01700000001')->first();
        $profile = $this->customers->presentProfile($customer);

        $this->assertSame(2, $profile['order_count']);
        $this->assertSame(3000.0, $profile['total_spent']);
        $this->assertCount(2, $profile['timeline']);
        $this->assertSame(1600.0, $profile['timeline'][0]['total_amount']);
        $this->assertSame(1400.0, $profile['timeline'][1]['total_amount']);
    }

    public function test_a_customer_history_shows_what_was_bought(): void
    {
        $this->sellTo('01700000001', 'Rahim', 1400);

        $customer = Customer::where('mobile_number', '01700000001')->first();
        $entry = $this->customers->presentProfile($customer)['timeline'][0];

        $this->assertSame(1, $entry['items'][0]['quantity']);
        $this->assertSame('Swap / refill', $entry['items'][0]['transaction_label']);
        $this->assertStringContainsString('Jamuna', $entry['items'][0]['display_name']);
        $this->assertSame('paid', $entry['payment_state']);
    }

    public function test_a_customer_history_reports_what_each_order_still_owes(): void
    {
        $this->sellTo('01700000001', 'Rahim', 1400, ['is_paid' => false, 'amount_paid' => 400]);

        $customer = Customer::where('mobile_number', '01700000001')->first();
        $profile = $this->customers->presentProfile($customer);

        $this->assertSame(400.0, $profile['timeline'][0]['paid_amount']);
        $this->assertSame(1000.0, $profile['timeline'][0]['due_amount']);
        $this->assertSame('partial', $profile['timeline'][0]['payment_state']);
        $this->assertSame(1000.0, $profile['due_amount']);
    }

    public function test_paying_at_delivery_does_not_add_a_second_timeline_entry(): void
    {
        $this->sellTo('01700000001', 'Rahim', 1400);

        $customer = Customer::where('mobile_number', '01700000001')->first();
        $timeline = $this->customers->presentProfile($customer)['timeline'];

        // The money changed hands during the sale, so it is part of that entry
        // rather than a separate visit.
        $this->assertCount(1, $timeline);
        $this->assertSame('sale', $timeline[0]['kind']);
    }

    public function test_settling_a_due_sale_later_adds_its_own_timeline_entry(): void
    {
        $this->sellTo('01700000001', 'Rahim', 1400, [
            'occurred_at' => now()->subDays(7)->toDateTimeString(),
            'is_paid'     => false,
        ]);

        $customer = Customer::where('mobile_number', '01700000001')->first();
        $this->settle($customer, 1400, now()->subDay());

        $timeline = $this->customers->presentProfile($customer)['timeline'];

        // Two moments: the sale a week ago, the payment yesterday.
        $this->assertCount(2, $timeline);

        $this->assertSame('payment', $timeline[0]['kind']);
        $this->assertSame(1400.0, $timeline[0]['amount']);
        $this->assertSame('Cash', $timeline[0]['method_label']);
        // Nothing left owing once this landed.
        $this->assertSame(0.0, $timeline[0]['due_amount']);

        $this->assertSame('sale', $timeline[1]['kind']);
    }

    public function test_a_sale_settled_later_is_not_badged_paid_at_the_time_of_sale(): void
    {
        $this->sellTo('01700000001', 'Rahim', 1400, [
            'occurred_at' => now()->subDays(7)->toDateTimeString(),
            'is_paid'     => false,
        ]);

        $customer = Customer::where('mobile_number', '01700000001')->first();
        $this->settle($customer, 1400, now()->subDay());

        $sale = collect($this->customers->presentProfile($customer)['timeline'])
            ->firstWhere('kind', 'sale');

        // The customer owed for a week. Badging the sale "Paid" because the
        // money arrived later would erase that.
        $this->assertSame('due', $sale['payment_state']);
        $this->assertSame(0.0, $sale['paid_amount']);
        $this->assertSame(1400.0, $sale['due_amount']);
        // ...but the balance is not still outstanding.
        $this->assertTrue($sale['settled_later']);
    }

    public function test_a_sale_still_owed_is_not_marked_settled_later(): void
    {
        $this->sellTo('01700000001', 'Rahim', 1400, [
            'occurred_at' => now()->subDays(7)->toDateTimeString(),
            'is_paid'     => false,
        ]);

        $customer = Customer::where('mobile_number', '01700000001')->first();
        $this->settle($customer, 400, now()->subDay());

        $sale = collect($this->customers->presentProfile($customer)['timeline'])
            ->firstWhere('kind', 'sale');

        $this->assertSame('due', $sale['payment_state']);
        $this->assertFalse($sale['settled_later']);
    }

    public function test_a_sale_paid_at_delivery_is_badged_paid(): void
    {
        $this->sellTo('01700000001', 'Rahim', 1400);

        $customer = Customer::where('mobile_number', '01700000001')->first();
        $sale = $this->customers->presentProfile($customer)['timeline'][0];

        $this->assertSame('paid', $sale['payment_state']);
        $this->assertSame(1400.0, $sale['paid_amount']);
        // Nothing was collected later, so there is nothing to explain.
        $this->assertFalse($sale['settled_later']);
    }

    public function test_each_instalment_gets_its_own_entry_with_the_balance_at_the_time(): void
    {
        $this->sellTo('01700000001', 'Rahim', 1400, [
            'occurred_at' => now()->subDays(10)->toDateTimeString(),
            'is_paid'     => false,
        ]);

        $customer = Customer::where('mobile_number', '01700000001')->first();
        $this->settle($customer, 400, now()->subDays(5));
        $this->settle($customer, 1000, now()->subDay());

        $timeline = $this->customers->presentProfile($customer)['timeline'];

        $this->assertCount(3, $timeline);

        // Newest first, and each shows what was still owed at that moment
        // rather than the sale's balance today.
        $this->assertSame(1000.0, $timeline[0]['amount']);
        $this->assertSame(0.0, $timeline[0]['due_amount']);

        $this->assertSame(400.0, $timeline[1]['amount']);
        $this->assertSame(1000.0, $timeline[1]['due_amount']);

        $this->assertSame('sale', $timeline[2]['kind']);
    }

    public function test_a_customer_history_page_loads(): void
    {
        $this->sellTo('01700000001', 'Rahim', 1400);
        $customer = Customer::where('mobile_number', '01700000001')->first();

        $this->actingAs($this->user)
            ->get("/customers/{$customer->id}/history")
            ->assertOk();
    }

    public function test_a_customer_name_mobile_and_address_can_be_updated(): void
    {
        $customer = Customer::create([
            'name'          => 'Rahim',
            'mobile_number' => '01700000001',
            'address'       => 'Old Road',
        ]);

        $this->actingAs($this->user)
            ->patch("/customers/update/{$customer->id}", [
                'name'          => 'Rahim Uddin',
                'mobile_number' => '01700000002',
                'address'       => 'New Road',
            ])
            ->assertRedirect('/customers');

        $this->assertSame([
            'name'          => 'Rahim Uddin',
            'mobile_number' => '01700000002',
            'address'       => 'New Road',
        ], $customer->fresh()->only(['name', 'mobile_number', 'address']));
    }

    public function test_a_mobile_number_cannot_be_taken_from_another_customer(): void
    {
        Customer::create(['name' => 'Karim', 'mobile_number' => '01700000002']);
        $rahim = Customer::create(['name' => 'Rahim', 'mobile_number' => '01700000001']);

        $this->actingAs($this->user)
            ->patch("/customers/update/{$rahim->id}", [
                'name'          => 'Rahim',
                'mobile_number' => '01700000002',
            ])
            ->assertSessionHasErrors('mobile_number');

        // The unique index covers trashed rows too, so a duplicate would fail
        // at the database rather than as a form error.
        $this->assertSame('01700000001', $rahim->fresh()->mobile_number);
    }

    public function test_a_customer_keeps_their_own_mobile_number_when_saved_unchanged(): void
    {
        $customer = Customer::create(['name' => 'Rahim', 'mobile_number' => '01700000001']);

        // The uniqueness rule must ignore the row being edited, or saving a
        // name change would collide with the customer's own number.
        $this->actingAs($this->user)
            ->patch("/customers/update/{$customer->id}", [
                'name'          => 'Rahim Uddin',
                'mobile_number' => '01700000001',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame('Rahim Uddin', $customer->fresh()->name);
    }

    public function test_clearing_a_mobile_number_stores_null_not_an_empty_string(): void
    {
        $first = Customer::create(['name' => 'Rahim', 'mobile_number' => '01700000001']);
        $second = Customer::create(['name' => 'Karim', 'mobile_number' => '01700000002']);

        foreach ([$first, $second] as $customer) {
            $this->actingAs($this->user)
                ->patch("/customers/update/{$customer->id}", [
                    'name'          => $customer->name,
                    'mobile_number' => '',
                ])
                ->assertSessionHasNoErrors();
        }

        // Empty strings would collide under the unique index; nulls do not, so
        // any number of customers may have no number at all.
        $this->assertNull($first->fresh()->mobile_number);
        $this->assertNull($second->fresh()->mobile_number);
    }

    public function test_a_customer_name_is_required(): void
    {
        $customer = Customer::create(['name' => 'Rahim', 'mobile_number' => '01700000001']);

        $this->actingAs($this->user)
            ->patch("/customers/update/{$customer->id}", ['name' => '', 'mobile_number' => '01700000001'])
            ->assertSessionHasErrors('name');
    }

    public function test_editing_a_customer_leaves_their_order_history_alone(): void
    {
        $this->sellTo('01700000001', 'Rahim', 1400, ['is_paid' => false, 'amount_paid' => 400]);

        $customer = Customer::where('mobile_number', '01700000001')->first();

        $this->actingAs($this->user)
            ->patch("/customers/update/{$customer->id}", [
                'name'          => 'Renamed',
                'mobile_number' => '01700000009',
            ]);

        $row = collect($this->customers->paginate()->items())->firstWhere('name', 'Renamed');

        // Spend and balance are derived from orders, so a contact-detail edit
        // must not disturb them.
        $this->assertSame(1, $row['order_count']);
        $this->assertSame(1400.0, $row['total_spent']);
        $this->assertSame(1000.0, $row['due_amount']);
    }
}
