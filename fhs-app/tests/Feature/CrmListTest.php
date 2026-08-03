<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\Catalogue;
use App\Models\Customer;
use App\Models\Order;
use App\Models\User;
use App\Services\CrmService;
use App\Services\InventoryService;
use App\Services\OrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Call lists: who is worth contacting, and why.
 *
 * Each list answers one question, so these assert who appears and in what
 * order — a call list is only useful if the most urgent are at the top.
 */
class CrmListTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private CrmService $crm;

    private OrderService $orders;

    private Catalogue $cylinder;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        config(['app.admin_emails' => [strtolower($this->user->email)]]);

        $this->crm = app(CrmService::class);
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
            'purchased_at'    => now()->subYear()->toDateString(),
            'filled_quantity' => 500,
            'shell_unit_cost' => 900,
            'gas_unit_cost'   => 800,
        ], $this->user->id);
    }

    /** A sale to a named customer, a given number of days ago. */
    private function sellTo(string $mobile, string $name, int $daysAgo): void
    {
        $this->orders->record([
            'mobile_number' => $mobile,
            'customer_name' => $name,
            'occurred_at'   => now()->subDays($daysAgo)->toDateTimeString(),
            'items'         => [[
                'catalogue_id'     => $this->cylinder->id,
                'transaction_type' => 'swap',
                'quantity'         => 1,
                'unit_price'       => 1400,
            ]],
            'is_paid' => true,
        ], $this->user->id);
    }

    /** @return array<int, string> */
    private function namesOn(string $filter, ?int $days = null, ?int $minOrders = null): array
    {
        return collect($this->crm->paginate($filter, $days, $minOrders)->items())
            ->pluck('name')
            ->all();
    }

    public function test_the_refill_list_holds_anyone_quiet_for_at_least_the_window(): void
    {
        $this->sellTo('01700000001', 'Overdue', 25);
        $this->sellTo('01700000002', 'Recent', 5);

        // 20 days is the default: long enough that a household is likely
        // through its cylinder.
        $this->assertSame(['Overdue'], $this->namesOn('due'));
    }

    public function test_the_refill_window_can_be_changed(): void
    {
        $this->sellTo('01700000001', 'Ten days', 10);
        $this->sellTo('01700000002', 'Three days', 3);

        $this->assertSame(['Ten days'], $this->namesOn('due', days: 7));
        $this->assertSame([], $this->namesOn('due', days: 30));
    }

    public function test_the_refill_list_puts_the_longest_overdue_first(): void
    {
        $this->sellTo('01700000001', 'Middle', 40);
        $this->sellTo('01700000002', 'Longest', 90);
        $this->sellTo('01700000003', 'Shortest', 22);

        // A call list is worked from the top, so the most overdue belong there.
        $this->assertSame(['Longest', 'Middle', 'Shortest'], $this->namesOn('due'));
    }

    public function test_only_a_customers_most_recent_order_counts(): void
    {
        // Bought long ago, but also last week — not due anything.
        $this->sellTo('01700000001', 'Regular', 200);
        $this->sellTo('01700000001', 'Regular', 6);

        $this->assertSame([], $this->namesOn('due'));
    }

    public function test_the_lapsed_list_uses_a_longer_window_than_the_refill_list(): void
    {
        $this->sellTo('01700000001', 'Gone quiet', 60);
        $this->sellTo('01700000002', 'Just due', 25);

        // 25 days is due a refill but not yet lapsed; the two lists are the
        // same measure at different thresholds.
        $this->assertSame(['Gone quiet', 'Just due'], $this->namesOn('due'));
        $this->assertSame(['Gone quiet'], $this->namesOn('lapsed'));
    }

    public function test_the_regulars_list_holds_anyone_at_or_above_the_threshold(): void
    {
        $this->sellTo('01700000001', 'Twice', 10);
        $this->sellTo('01700000001', 'Twice', 5);
        $this->sellTo('01700000002', 'Once', 8);

        // Two orders is the default: enough to be a returning customer.
        $this->assertSame(['Twice'], $this->namesOn('repeat'));
    }

    public function test_the_regulars_threshold_can_be_changed(): void
    {
        foreach ([30, 20, 10] as $daysAgo) {
            $this->sellTo('01700000001', 'Three times', $daysAgo);
        }

        $this->sellTo('01700000002', 'Twice', 15);
        $this->sellTo('01700000002', 'Twice', 5);

        $this->assertSame(['Three times', 'Twice'], $this->namesOn('repeat', minOrders: 2));
        $this->assertSame(['Three times'], $this->namesOn('repeat', minOrders: 3));
    }

    public function test_the_regulars_list_puts_the_most_frequent_first(): void
    {
        foreach ([30, 20, 10] as $daysAgo) {
            $this->sellTo('01700000001', 'Best', $daysAgo);
        }

        $this->sellTo('01700000002', 'Good', 15);
        $this->sellTo('01700000002', 'Good', 5);

        $this->assertSame(['Best', 'Good'], $this->namesOn('repeat'));
    }

    public function test_a_customer_who_never_ordered_is_on_no_list(): void
    {
        Customer::create(['name' => 'Never bought', 'mobile_number' => '01700000009']);

        // There is no rhythm to have fallen out of and nothing to refill.
        $this->assertSame([], $this->namesOn('due'));
        $this->assertSame([], $this->namesOn('lapsed'));
        $this->assertSame([], $this->namesOn('repeat', minOrders: 1));
    }

    public function test_a_failed_order_does_not_count_as_having_bought(): void
    {
        $this->sellTo('01700000001', 'Failed only', 5);
        Order::query()->latest('id')->first()->update(['status' => 'failed']);

        // The sale never happened, so they are neither a customer to refill nor
        // one who has gone quiet.
        $this->assertSame([], $this->namesOn('due'));
        $this->assertSame([], $this->namesOn('repeat', minOrders: 1));
    }

    public function test_each_row_carries_how_long_it_has_been(): void
    {
        $this->sellTo('01700000001', 'Overdue', 33);

        $row = $this->crm->paginate('due')->items()[0];

        $this->assertSame(33, $row['days_since_order']);
        $this->assertSame(1, $row['order_count']);
        $this->assertSame(1400.0, $row['total_spent']);
    }

    public function test_the_crm_page_loads_with_the_default_list(): void
    {
        $this->sellTo('01700000001', 'Overdue', 25);

        $this->actingAs($this->user)
            ->get('/crm')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('crm/index')
                ->where('active.filter', 'due')
                ->has('customers.data', 1)
                ->etc()
            );
    }

    public function test_the_page_accepts_a_filter_and_a_threshold(): void
    {
        $this->sellTo('01700000001', 'Ten days', 10);

        $this->actingAs($this->user)
            ->get('/crm?filter=due&days=7')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('customers.data', 1)->etc());
    }

    public function test_an_unknown_filter_is_rejected(): void
    {
        $this->actingAs($this->user)
            ->get('/crm?filter=nonsense')
            ->assertSessionHasErrors('filter');
    }

    public function test_the_crm_page_is_admin_only(): void
    {
        $outsider = User::factory()->create();

        $this->actingAs($outsider)
            ->get('/crm')
            ->assertForbidden();
    }
}
