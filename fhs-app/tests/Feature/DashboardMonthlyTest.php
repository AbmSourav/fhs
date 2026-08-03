<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\Catalogue;
use App\Models\Order;
use App\Models\User;
use App\Services\DashboardService;
use App\Services\ExpenseService;
use App\Services\InventoryService;
use App\Services\OrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * This month's trading, against the month before it.
 *
 * The month boundary is the point of these: the business runs on Dhaka time
 * while timestamps are stored in UTC, so a sale in the first six hours of a
 * month would land in the previous one if the boundary were taken naively.
 */
class DashboardMonthlyTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private DashboardService $dashboard;

    private OrderService $orders;

    private Catalogue $cylinder;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        config(['app.admin_emails' => [strtolower($this->user->email)]]);

        $this->dashboard = app(DashboardService::class);
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

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /** A sale at a given UTC instant. */
    private function sellAt(string $utc, float $price = 1400, bool $paid = true): Order
    {
        return $this->orders->record([
            'mobile_number' => '01700000001',
            'customer_name' => 'Rahim',
            'occurred_at'   => $utc,
            'items'         => [[
                'catalogue_id'     => $this->cylinder->id,
                'transaction_type' => 'swap',
                'quantity'         => 1,
                'unit_price'       => $price,
            ]],
            'is_paid' => $paid,
        ], $this->user->id);
    }

    public function test_a_sale_just_after_midnight_dhaka_counts_in_the_new_month(): void
    {
        Carbon::setTestNow('2026-08-15 12:00:00');

        // 02:00 on 1 August in Dhaka is 20:00 on 31 July in UTC. Taking the
        // month boundary in UTC would file this under July.
        $this->sellAt('2026-07-31 20:00:00');

        $month = $this->dashboard->monthlyFigures();

        $this->assertSame(1400.0, $month['revenue']['current']);
        $this->assertSame(0.0, $month['revenue']['previous']);
    }

    public function test_a_sale_late_on_the_last_day_counts_in_the_old_month(): void
    {
        Carbon::setTestNow('2026-08-15 12:00:00');

        // 23:30 on 31 July in Dhaka — the mirror case, which catches a
        // correction applied in the wrong direction.
        $this->sellAt('2026-07-31 17:30:00');

        $month = $this->dashboard->monthlyFigures();

        $this->assertSame(0.0, $month['revenue']['current']);
        $this->assertSame(1400.0, $month['revenue']['previous']);
    }

    public function test_a_sale_on_the_boundary_instant_is_counted_once(): void
    {
        Carbon::setTestNow('2026-08-15 12:00:00');

        // Exactly midnight on 1 August, Dhaka. The range is half-open, so this
        // belongs to August alone rather than to both months.
        $this->sellAt('2026-07-31 18:00:00');

        $month = $this->dashboard->monthlyFigures();

        $this->assertSame(1400.0, $month['revenue']['current']);
        $this->assertSame(0.0, $month['revenue']['previous']);
    }

    public function test_the_month_is_labelled_in_business_time(): void
    {
        // Already 1 August in Dhaka, though still July by UTC.
        Carbon::setTestNow('2026-07-31 21:00:00');

        $month = $this->dashboard->monthlyFigures();

        $this->assertSame('August 2026', $month['month_label']);
        $this->assertSame('July 2026', $month['previous_month_label']);
    }

    public function test_sales_count_and_average_sale_are_reported(): void
    {
        Carbon::setTestNow('2026-08-15 12:00:00');

        $this->sellAt('2026-08-10 06:00:00', 1000);
        $this->sellAt('2026-08-11 06:00:00', 2000);

        $month = $this->dashboard->monthlyFigures();

        $this->assertSame(2, $month['sales_count']['current']);
        $this->assertSame(3000.0, $month['revenue']['current']);
        $this->assertSame(1500.0, $month['average_order']['current']);
    }

    public function test_a_month_with_no_sales_has_no_average_rather_than_a_division_error(): void
    {
        Carbon::setTestNow('2026-08-15 12:00:00');

        $this->assertSame(0.0, $this->dashboard->monthlyFigures()['average_order']['current']);
    }

    public function test_gross_profit_uses_the_cost_frozen_at_sale_time(): void
    {
        Carbon::setTestNow('2026-08-15 12:00:00');

        $this->sellAt('2026-08-10 06:00:00', 1400);

        // Gas cost 800 at the time of sale.
        $this->assertSame(600.0, $this->dashboard->monthlyFigures()['gross_profit']['current']);
    }

    public function test_a_first_ever_month_reports_new_rather_than_a_percentage(): void
    {
        Carbon::setTestNow('2026-08-15 12:00:00');

        $this->sellAt('2026-08-10 06:00:00');

        $revenue = $this->dashboard->monthlyFigures()['revenue'];

        // A percentage against a zero base is undefined, not infinite, and not
        // 100%.
        $this->assertNull($revenue['percent']);
        $this->assertSame('new', $revenue['direction']);
    }

    public function test_two_empty_months_report_flat_with_no_percentage(): void
    {
        Carbon::setTestNow('2026-08-15 12:00:00');

        $revenue = $this->dashboard->monthlyFigures()['revenue'];

        $this->assertNull($revenue['percent']);
        $this->assertSame('flat', $revenue['direction']);
        $this->assertSame(0.0, $revenue['current']);
    }

    public function test_a_decline_reports_a_negative_delta_and_a_down_direction(): void
    {
        Carbon::setTestNow('2026-08-15 12:00:00');

        $this->sellAt('2026-07-10 06:00:00', 2000);
        $this->sellAt('2026-08-10 06:00:00', 1500);

        $revenue = $this->dashboard->monthlyFigures()['revenue'];

        $this->assertSame(-500.0, $revenue['delta']);
        $this->assertSame(-25.0, $revenue['percent']);
        $this->assertSame('down', $revenue['direction']);
    }

    public function test_a_rise_reports_an_up_direction(): void
    {
        Carbon::setTestNow('2026-08-15 12:00:00');

        $this->sellAt('2026-07-10 06:00:00', 1000);
        $this->sellAt('2026-08-10 06:00:00', 1500);

        $revenue = $this->dashboard->monthlyFigures()['revenue'];

        $this->assertSame(50.0, $revenue['percent']);
        $this->assertSame('up', $revenue['direction']);
    }

    public function test_collected_counts_when_payment_arrived_not_when_the_sale_happened(): void
    {
        Carbon::setTestNow('2026-08-15 12:00:00');

        // Sold in July on credit, settled in August.
        $order = $this->sellAt('2026-07-20 06:00:00', 1400, paid: false);

        $this->orders->settle($order, [
            'amount'  => 1400,
            'method'  => 'cash',
            'paid_at' => '2026-08-05 06:00:00',
        ], $this->user->id);

        $month = $this->dashboard->monthlyFigures();

        // Revenue belongs to July, the cash to August. They are different
        // measures and are meant to disagree here.
        $this->assertSame(0.0, $month['revenue']['current']);
        $this->assertSame(1400.0, $month['revenue']['previous']);
        $this->assertSame(1400.0, $month['collected']['current']);
    }

    public function test_expenses_are_scoped_to_the_month_they_were_spent_in(): void
    {
        Carbon::setTestNow('2026-08-15 12:00:00');

        $expenses = app(ExpenseService::class);

        foreach (['2026-08-05 06:00:00' => 500, '2026-07-20 06:00:00' => 900] as $spentAt => $amount) {
            $expenses->record([
                'category'       => 'utilities',
                'description'    => 'Electricity',
                'amount'         => $amount,
                'payment_method' => 'cash',
                'spent_at'       => $spentAt,
            ], $this->user->id);
        }

        $month = $this->dashboard->monthlyFigures();

        $this->assertSame(500.0, $month['expenses']['current']);
        $this->assertSame(900.0, $month['expenses']['previous']);
    }

    public function test_consignment_transport_counts_toward_the_month_it_arrived(): void
    {
        Carbon::setTestNow('2026-08-15 12:00:00');

        app(InventoryService::class)->record([
            'catalogue_id'    => $this->cylinder->id,
            'purchased_at'    => '2026-08-08 06:00:00',
            'filled_quantity' => 10,
            'gas_unit_cost'   => 800,
            'transport_cost'  => 450,
        ], $this->user->id);

        // Delivery is money out, recorded on the purchase rather than in the
        // expenses table — but it is still an expense.
        $this->assertSame(450.0, $this->dashboard->monthlyFigures()['expenses']['current']);
    }

    public function test_a_failed_order_is_excluded_from_the_month(): void
    {
        Carbon::setTestNow('2026-08-15 12:00:00');

        $this->sellAt('2026-08-10 06:00:00');
        Order::query()->latest('id')->first()->update(['status' => 'failed']);

        $month = $this->dashboard->monthlyFigures();

        $this->assertSame(0.0, $month['revenue']['current']);
        $this->assertSame(0, $month['sales_count']['current']);
    }

    public function test_the_monthly_series_always_has_twelve_buckets(): void
    {
        Carbon::setTestNow('2026-08-15 12:00:00');

        $this->sellAt('2026-08-10 06:00:00');

        $monthly = $this->dashboard->trends()['monthly'];

        // A quiet month is a real data point. Deriving the axis from the rows
        // returned would silently shorten it.
        $this->assertCount(12, $monthly);
        $this->assertSame('Aug', $monthly[11]['label']);
        $this->assertSame('Sep', $monthly[0]['label']);
    }

    public function test_an_empty_month_appears_as_a_zero(): void
    {
        Carbon::setTestNow('2026-08-15 12:00:00');

        $monthly = $this->dashboard->trends()['monthly'];

        $this->assertSame(0.0, $monthly[11]['revenue']);
        $this->assertSame(0.0, $monthly[11]['collected']);
    }

    public function test_a_sale_after_midnight_dhaka_lands_in_the_right_month_bucket(): void
    {
        Carbon::setTestNow('2026-08-15 12:00:00');

        // 02:00 on 1 August, Dhaka.
        $this->sellAt('2026-07-31 20:00:00', 1400);

        $monthly = $this->dashboard->trends()['monthly'];

        $this->assertSame(1400.0, $monthly[11]['revenue']);   // August
        $this->assertSame(0.0, $monthly[10]['revenue']);      // July
    }

    public function test_a_sale_after_midnight_dhaka_lands_on_the_right_day_bucket(): void
    {
        Carbon::setTestNow('2026-08-15 12:00:00');

        // The case a UTC-based day grouping gets wrong: this is the 1st in
        // Dhaka but still the 31st by UTC.
        $this->sellAt('2026-07-31 20:00:00', 1400);

        $daily = $this->dashboard->trends()['daily'];

        $this->assertSame('1', $daily[0]['label']);
        $this->assertSame(1400.0, $daily[0]['revenue']);
    }

    public function test_the_daily_series_covers_every_day_of_the_month(): void
    {
        Carbon::setTestNow('2026-08-15 12:00:00');

        // August has 31 days, and each is present whether or not it traded.
        $this->assertCount(31, $this->dashboard->trends()['daily']);
    }

    public function test_the_daily_series_follows_the_length_of_the_month(): void
    {
        Carbon::setTestNow('2026-02-15 12:00:00');

        $this->assertCount(28, $this->dashboard->trends()['daily']);
    }

    public function test_the_transaction_mix_splits_swap_from_outright(): void
    {
        Carbon::setTestNow('2026-08-15 12:00:00');

        $this->sellAt('2026-08-10 06:00:00');

        $this->orders->record([
            'mobile_number' => '01700000002',
            'customer_name' => 'Karim',
            'occurred_at'   => '2026-08-11 06:00:00',
            'items'         => [[
                'catalogue_id'     => $this->cylinder->id,
                'transaction_type' => 'buy_with_gas',
                'quantity'         => 2,
                'unit_price'       => 3000,
            ]],
            'is_paid' => true,
        ], $this->user->id);

        $august = $this->dashboard->trends()['monthly'][11];

        // Counted in units, not lines: a two-cylinder sale is two cylinders
        // leaving the business.
        $this->assertSame(1.0, $august['swap']);
        $this->assertSame(2.0, $august['outright']);
    }

    public function test_billed_and_collected_diverge_in_the_series(): void
    {
        Carbon::setTestNow('2026-08-15 12:00:00');

        $order = $this->sellAt('2026-07-20 06:00:00', 1400, paid: false);

        $this->orders->settle($order, [
            'amount'  => 1400,
            'method'  => 'cash',
            'paid_at' => '2026-08-05 06:00:00',
        ], $this->user->id);

        $monthly = $this->dashboard->trends()['monthly'];

        // July billed it, August collected it — which is the gap the chart is
        // there to show.
        $this->assertSame(1400.0, $monthly[10]['revenue']);
        $this->assertSame(0.0, $monthly[10]['collected']);
        $this->assertSame(0.0, $monthly[11]['revenue']);
        $this->assertSame(1400.0, $monthly[11]['collected']);
    }

    public function test_the_dashboard_passes_the_month_to_the_page(): void
    {
        Carbon::setTestNow('2026-08-15 12:00:00');

        $this->sellAt('2026-08-10 06:00:00', 1400);

        $this->actingAs($this->user)
            ->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('dashboard')
                ->where('month.month_label', 'August 2026')
                ->where('month.revenue.current', 1400)
                ->where('month.sales_count.current', 1)
                ->etc()
            );
    }
}
