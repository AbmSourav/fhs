<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\Catalogue;
use App\Models\Expense;
use App\Models\Order;
use App\Models\User;
use App\Services\DashboardService;
use App\Services\ExpenseService;
use App\Services\InventoryService;
use App\Services\OrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The dashboard's life-of-the-business totals.
 *
 * The distinction under test throughout: buying stock converts money into
 * goods, and only selling them turns that into a cost. A cylinder shell is
 * never consumed at all — a swap returns it, so it stays an asset.
 */
class DashboardStatisticsTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private DashboardService $dashboard;

    private OrderService $orders;

    private InventoryService $inventory;

    private ExpenseService $expenses;

    private Catalogue $cylinder;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        config(['app.admin_emails' => [strtolower($this->user->email)]]);

        $this->dashboard = app(DashboardService::class);
        $this->orders = app(OrderService::class);
        $this->inventory = app(InventoryService::class);
        $this->expenses = app(ExpenseService::class);

        $brand = Brand::create(['name' => 'Jamuna', 'slug' => 'jamuna']);
        $this->cylinder = Catalogue::create([
            'type'          => 'lpg_cylinder',
            'brand_id'      => $brand->id,
            'weight'        => 12.5,
            'is_gas'        => true,
            'is_returnable' => true,
        ]);
    }

    /** Buy cylinders: 10 shells at 900, gas at 800. */
    private function buyStock(array $overrides = []): void
    {
        $this->inventory->record([
            'catalogue_id'    => $this->cylinder->id,
            'purchased_at'    => now()->subDays(10)->toDateString(),
            'filled_quantity' => 10,
            'empty_quantity'  => 0,
            'shell_unit_cost' => 900,
            'gas_unit_cost'   => 800,
            ...$overrides,
        ], $this->user->id);
    }

    private function sell(string $type = 'swap', float $price = 1400, int $quantity = 1): void
    {
        $this->orders->record([
            'mobile_number' => '01700000001',
            'customer_name' => 'Rahim',
            'occurred_at'   => now()->toDateTimeString(),
            'items'         => [[
                'catalogue_id'     => $this->cylinder->id,
                'transaction_type' => $type,
                'quantity'         => $quantity,
                'unit_price'       => $price,
            ]],
            'is_paid' => true,
        ], $this->user->id);
    }

    private function spend(float $amount): void
    {
        $this->expenses->record([
            'category'       => 'transport',
            'description'    => 'Van fuel',
            'amount'         => $amount,
            'payment_method' => 'cash',
            'spent_at'       => now()->toDateTimeString(),
        ], $this->user->id);
    }

    public function test_an_empty_business_reports_zero_rather_than_null(): void
    {
        $position = $this->dashboard->allTimePosition();

        // SUM over no rows is NULL in SQL; every figure must still be a number.
        $this->assertSame(0.0, $position['revenue']);
        $this->assertSame(0.0, $position['cogs']);
        $this->assertSame(0.0, $position['net_profit']);
        $this->assertSame(0.0, $position['current_assets']);
    }

    public function test_buying_stock_does_not_reduce_profit(): void
    {
        $this->buyStock();

        $position = $this->dashboard->allTimePosition();

        // The money became goods rather than being spent. Nothing has sold, so
        // there is no cost yet and no profit either way.
        $this->assertSame(0.0, $position['cogs']);
        $this->assertSame(0.0, $position['net_profit']);
    }

    public function test_buying_stock_raises_current_assets(): void
    {
        $this->buyStock();

        $position = $this->dashboard->allTimePosition();

        // 10 shells at 900, and gas for 10 filled at 800.
        $this->assertSame(9000.0, $position['shell_value']);
        $this->assertSame(8000.0, $position['stock_value']);
        $this->assertSame(17000.0, $position['current_assets']);
    }

    public function test_a_swap_costs_the_gas_only(): void
    {
        $this->buyStock();
        $this->sell('swap', 1400);

        $position = $this->dashboard->allTimePosition();

        // The customer returned an empty, so only gas left the business.
        $this->assertSame(800.0, $position['cogs']);
        $this->assertSame(600.0, $position['gross_profit']);
    }

    public function test_a_swap_leaves_the_cylinder_as_an_asset(): void
    {
        $this->buyStock();
        $this->sell('swap', 1400);

        // The steel stays owned: an empty came back in place of the filled one.
        $this->assertSame(9000.0, $this->dashboard->allTimePosition()['shell_value']);
    }

    public function test_selling_a_cylinder_outright_costs_the_shell_too(): void
    {
        $this->buyStock();
        $this->sell('buy_with_gas', 3000);

        $position = $this->dashboard->allTimePosition();

        // Gas 800 plus the shell 900: the cylinder is gone for good.
        $this->assertSame(1700.0, $position['cogs']);
        // ...and it is no longer an asset. Nine bought, one sold.
        $this->assertSame(8100.0, $position['shell_value']);
    }

    public function test_selling_reduces_stock_on_hand_by_what_it_cost(): void
    {
        $this->buyStock();
        $this->sell('swap', 1400);

        // Gas for ten was bought; one has now gone.
        $this->assertSame(7200.0, $this->dashboard->allTimePosition()['stock_value']);
    }

    public function test_revenue_totals_everything_ever_billed(): void
    {
        $this->buyStock();
        $this->sell('swap', 1400);
        $this->sell('swap', 1600);

        $this->assertSame(3000.0, $this->dashboard->allTimePosition()['revenue']);
    }

    public function test_sales_are_counted_across_the_whole_life_of_the_business(): void
    {
        $this->buyStock();
        $this->sell('swap', 1400);
        $this->sell('swap', 1600);

        // Counted on orders, so a sale with several lines still counts once.
        $this->assertSame(2, $this->dashboard->allTimePosition()['sales_count']);
    }

    public function test_a_failed_order_is_excluded_from_every_figure(): void
    {
        $this->buyStock();
        $this->sell('swap', 1400);

        Order::query()->latest('id')->first()->update(['status' => 'failed']);

        $position = $this->dashboard->allTimePosition();

        // A failed sale never happened: it earned nothing and cost nothing,
        // and the goods are still on the shelf.
        $this->assertSame(0.0, $position['revenue']);
        $this->assertSame(0, $position['sales_count']);
        $this->assertSame(0.0, $position['cogs']);
        $this->assertSame(8000.0, $position['stock_value']);
    }

    public function test_an_edited_purchase_is_counted_once_not_twice(): void
    {
        $purchase = $this->inventory->record([
            'catalogue_id'    => $this->cylinder->id,
            'purchased_at'    => now()->toDateString(),
            'filled_quantity' => 10,
            'shell_unit_cost' => 0,
            'gas_unit_cost'   => 800,
        ], $this->user->id);

        $this->inventory->edit($purchase, [
            'catalogue_id'    => $this->cylinder->id,
            'purchased_at'    => now()->toDateString(),
            'filled_quantity' => 10,
            'shell_unit_cost' => 0,
            'gas_unit_cost'   => 900,
        ], $this->user->id);

        // Corrections append a replacement row rather than rewriting, so
        // without current() this would report 8000 + 9000.
        $this->assertSame(9000.0, $this->dashboard->allTimePosition()['stock_value']);
    }

    public function test_a_swap_purchase_acquires_no_shells(): void
    {
        $this->buyStock();

        // Refilling: empties go out, filled cylinders come back. No new steel.
        $this->inventory->record([
            'catalogue_id'      => $this->cylinder->id,
            'swap_catalogue_id' => $this->cylinder->id,
            'purchased_at'      => now()->toDateString(),
            'filled_quantity'   => 5,
            'empty_quantity'    => 5,
            'shell_unit_cost'   => 900,
            'gas_unit_cost'     => 800,
        ], $this->user->id);

        // Still the ten cylinders originally bought, despite the swap naming a
        // shell cost.
        $this->assertSame(9000.0, $this->dashboard->allTimePosition()['shell_value']);
    }

    public function test_consignment_transport_counts_as_an_expense(): void
    {
        $this->buyStock(['transport_cost' => 500]);
        $this->spend(300);

        // Getting a delivery to the premises is money out, recorded on the
        // purchase rather than in the expenses table.
        $this->assertSame(800.0, $this->dashboard->allTimePosition()['other_expenses']);
    }

    public function test_transport_is_not_counted_twice(): void
    {
        $this->buyStock(['transport_cost' => 500]);

        $position = $this->dashboard->allTimePosition();

        // Being an expense, it must not also inflate what the unsold stock is
        // worth — 10 filled at 800 gas, with transport counted apart from it.
        $this->assertSame(8000.0, $position['stock_value']);
        $this->assertSame(500.0, $position['other_expenses']);
    }

    public function test_other_expenses_reduce_net_but_not_gross_profit(): void
    {
        $this->buyStock();
        $this->sell('swap', 1400);
        $this->spend(200);

        $position = $this->dashboard->allTimePosition();

        // Gross is trading alone; net carries the running costs.
        $this->assertSame(600.0, $position['gross_profit']);
        $this->assertSame(400.0, $position['net_profit']);
    }

    public function test_a_deleted_expense_stops_counting(): void
    {
        $this->spend(1200);
        $this->spend(800);

        $this->expenses->delete(Expense::query()->latest('id')->first());

        // A soft-deleted expense silently dragging on profit is exactly the
        // kind of error nobody notices, so it is asserted rather than assumed.
        $this->assertSame(1200.0, $this->dashboard->allTimePosition()['other_expenses']);
    }

    public function test_spending_more_than_earned_reports_a_negative_profit(): void
    {
        $this->buyStock();
        $this->sell('swap', 1400);
        $this->spend(5000);

        // Reported as a loss rather than clamped at zero.
        $this->assertSame(-4400.0, $this->dashboard->allTimePosition()['net_profit']);
    }

    public function test_stock_value_never_goes_negative(): void
    {
        // Selling with nothing recorded as bought means the purchase records
        // are incomplete, not that stock is worth less than nothing.
        $this->sell('swap', 1400);

        $this->assertSame(0.0, $this->dashboard->allTimePosition()['stock_value']);
    }

    public function test_the_statistics_page_receives_the_position(): void
    {
        $this->buyStock();
        $this->sell('swap', 1400);

        $this->actingAs($this->user)
            ->get('/statistics')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('statistics/index')
                ->where('position.revenue', 1400)
                ->where('position.cogs', 800)
                ->where('position.gross_profit', 600)
                ->where('position.current_assets', 16200)
            );
    }

    public function test_statistics_are_admin_only(): void
    {
        $outsider = User::factory()->create();

        // Revenue and margin are on this page, so it is gated like every other
        // feature route — unlike the dashboard, which everyone lands on.
        $this->actingAs($outsider)
            ->get('/statistics')
            ->assertForbidden();
    }

    public function test_a_non_admin_still_sees_the_dashboard(): void
    {
        $outsider = User::factory()->create();

        // The dashboard is where everyone lands after logging in, so gating it
        // would 403 a non-admin immediately after sign-in.
        $this->actingAs($outsider)
            ->get('/dashboard')
            ->assertOk();
    }
}
