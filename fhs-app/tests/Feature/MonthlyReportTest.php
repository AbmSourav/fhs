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
 * A month's trading, as a report.
 *
 * Unlike the dashboard these cover an arbitrary month rather than the one in
 * progress, so the month boundary has to hold for a period nobody is standing
 * in — which is where a naive UTC boundary would go unnoticed.
 */
class MonthlyReportTest extends TestCase
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
            'purchased_at'    => '2025-01-01',
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

    /**
     * The report's markup, as the PDF template renders it.
     *
     * Asserted against the view rather than the finished PDF: dompdf deflates
     * its content streams, so words are not findable in the file, and what is
     * being checked here is what the template chose to draw.
     */
    private function reportHtml(string $month): string
    {
        return view('reports.monthly', [
            'report'      => app(DashboardService::class)->monthlyReport($month),
            'money'       => fn (float $amount) => 'Tk '.number_format(abs($amount)),
            'generatedAt' => '15 Aug 2026, 6:00pm',
            'generatedBy' => $this->user->name,
        ])->render();
    }

    /** A sale at a given UTC instant. */
    private function sellAt(string $utc, float $price = 1400): Order
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
            'is_paid' => true,
        ], $this->user->id);
    }

    public function test_a_report_covers_the_month_it_names(): void
    {
        Carbon::setTestNow('2026-08-15 12:00:00');

        $this->sellAt('2026-06-10 06:00:00');
        $this->sellAt('2026-07-10 06:00:00');
        $this->sellAt('2026-07-12 06:00:00');

        $report = $this->dashboard->monthlyReport('2026-07');

        $this->assertSame('July 2026', $report['month_label']);
        $this->assertSame(2, $report['sales_count']);
        $this->assertSame(2800.0, $report['revenue']);
    }

    public function test_a_report_carries_every_figure_it_promises(): void
    {
        Carbon::setTestNow('2026-08-15 12:00:00');

        // Gas cost 800, so a swap at 1400 leaves 600 gross.
        $this->sellAt('2026-07-10 06:00:00');

        app(ExpenseService::class)->record([
            'category'       => 'transport',
            'description'    => 'Van diesel',
            'amount'         => 250,
            'payment_method' => 'cash',
            'spent_at'       => '2026-07-11 06:00:00',
        ], $this->user->id);

        $report = $this->dashboard->monthlyReport('2026-07');

        $this->assertSame(1400.0, $report['revenue']);
        $this->assertSame(1, $report['sales_count']);
        $this->assertSame(800.0, $report['cogs']);
        $this->assertSame(600.0, $report['gross_profit']);
        $this->assertSame(250.0, $report['expenses']);
        $this->assertSame(350.0, $report['net_profit']);
    }

    public function test_a_sale_just_after_midnight_dhaka_belongs_to_the_new_month(): void
    {
        Carbon::setTestNow('2026-08-15 12:00:00');

        // 02:00 on 1 July in Dhaka is 20:00 on 30 June in UTC. A UTC month
        // boundary would file this under June.
        $this->sellAt('2026-06-30 20:00:00');

        $this->assertSame(1400.0, $this->dashboard->monthlyReport('2026-07')['revenue']);
        $this->assertSame(0.0, $this->dashboard->monthlyReport('2026-06')['revenue']);
    }

    public function test_a_sale_late_on_the_last_day_belongs_to_the_old_month(): void
    {
        Carbon::setTestNow('2026-08-15 12:00:00');

        // 23:30 on 30 June in Dhaka — the mirror case, which catches a
        // correction applied in the wrong direction.
        $this->sellAt('2026-06-30 17:30:00');

        $this->assertSame(0.0, $this->dashboard->monthlyReport('2026-07')['revenue']);
        $this->assertSame(1400.0, $this->dashboard->monthlyReport('2026-06')['revenue']);
    }

    public function test_a_month_with_no_trading_reports_zeroes(): void
    {
        Carbon::setTestNow('2026-08-15 12:00:00');

        $report = $this->dashboard->monthlyReport('2026-03');

        // A quiet month is a real answer, not an error.
        $this->assertSame(0.0, $report['revenue']);
        $this->assertSame(0, $report['sales_count']);
        $this->assertSame(0.0, $report['net_profit']);
        $this->assertSame(0.0, $report['average_order']);
    }

    public function test_a_failed_order_is_left_out(): void
    {
        Carbon::setTestNow('2026-08-15 12:00:00');

        $this->sellAt('2026-07-10 06:00:00');
        Order::query()->latest('id')->first()->update(['status' => 'failed']);

        $report = $this->dashboard->monthlyReport('2026-07');

        $this->assertSame(0.0, $report['revenue']);
        $this->assertSame(0, $report['sales_count']);
    }

    public function test_the_month_list_runs_from_the_first_sale_to_now(): void
    {
        Carbon::setTestNow('2026-08-15 12:00:00');

        $this->sellAt('2026-06-10 06:00:00');

        $months = array_column($this->dashboard->reportableMonths(), 'value');

        // Newest first: a report is usually wanted for the month just gone.
        $this->assertSame(['2026-08', '2026-07', '2026-06'], $months);
    }

    public function test_the_month_list_offers_this_month_before_anything_is_sold(): void
    {
        Carbon::setTestNow('2026-08-15 12:00:00');

        $months = $this->dashboard->reportableMonths();

        $this->assertCount(1, $months);
        $this->assertSame('2026-08', $months[0]['value']);
        $this->assertSame('August 2026', $months[0]['label']);
    }

    public function test_the_month_list_is_built_in_business_time(): void
    {
        // Already 1 August in Dhaka, though still July by UTC.
        Carbon::setTestNow('2026-07-31 21:00:00');

        $this->assertSame('2026-08', $this->dashboard->reportableMonths()[0]['value']);
    }

    public function test_the_page_shows_the_month_that_was_asked_for(): void
    {
        Carbon::setTestNow('2026-08-15 12:00:00');

        $this->sellAt('2026-06-10 06:00:00');

        $this->actingAs($this->user)
            ->get('/reports?month=2026-06')
            ->assertInertia(fn ($page) => $page
                ->component('reports/index')
                ->where('report.month', '2026-06')
                ->where('report.revenue', 1400));
    }

    public function test_the_page_falls_back_to_the_latest_month(): void
    {
        Carbon::setTestNow('2026-08-15 12:00:00');

        // A mistyped or hand-edited URL should still render a usable report
        // rather than an error page.
        foreach (['not-a-month', '2026-13', '', '2099-01'] as $month) {
            $this->actingAs($this->user)
                ->get('/reports?month='.$month)
                ->assertOk()
                ->assertInertia(fn ($page) => $page->where('report.month', '2026-08'));
        }
    }

    public function test_reports_are_admin_only(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/reports')
            ->assertForbidden();
    }

    public function test_the_download_returns_a_pdf_named_for_its_month(): void
    {
        Carbon::setTestNow('2026-08-15 12:00:00');

        $this->sellAt('2026-07-10 06:00:00');

        $response = $this->actingAs($this->user)->get('/reports/download?month=2026-07');

        $response->assertOk();
        $response->assertHeader('content-type', 'application/pdf');
        $response->assertDownload('fhs-report-july-2026.pdf');
    }

    public function test_the_pdf_is_built_from_the_database_not_the_page(): void
    {
        Carbon::setTestNow('2026-08-15 12:00:00');

        $this->sellAt('2026-07-10 06:00:00');

        $content = $this->actingAs($this->user)
            ->get('/reports/download?month=2026-07')
            ->getContent();

        // A real PDF, produced server-side. Nothing the browser holds took part
        // in making it, which is the whole point of the route.
        $this->assertStringStartsWith('%PDF-', $content);
        $this->assertNotEmpty($content);
    }

    public function test_the_download_falls_back_like_the_page_does(): void
    {
        Carbon::setTestNow('2026-08-15 12:00:00');

        // A hand-edited URL must not be able to make the PDF report a month the
        // page itself would refuse to show.
        $this->actingAs($this->user)
            ->get('/reports/download?month=2099-01')
            ->assertDownload('fhs-report-august-2026.pdf');
    }

    public function test_the_download_is_admin_only(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/reports/download')
            ->assertForbidden();
    }

    public function test_the_download_requires_signing_in(): void
    {
        $this->get('/reports/download')->assertRedirect('/login');
    }

    public function test_the_preview_shows_the_pdf_in_the_browser(): void
    {
        Carbon::setTestNow('2026-08-15 12:00:00');

        $this->sellAt('2026-07-10 06:00:00');

        $response = $this->actingAs($this->user)->get('/reports/preview?month=2026-07');

        $response->assertOk();
        $response->assertHeader('content-type', 'application/pdf');
        // inline, not attachment: the point is to see it without saving it.
        $this->assertStringContainsString('inline', $response->headers->get('content-disposition'));
        $this->assertStringStartsWith('%PDF-', $response->getContent());
    }

    public function test_the_report_names_whoever_generated_it(): void
    {
        Carbon::setTestNow('2026-08-15 12:00:00');

        $this->sellAt('2026-07-10 06:00:00');

        // A document that leaves the business should say who produced it.
        $this->assertStringContainsString(
            "Generated by {$this->user->name}",
            $this->reportHtml('2026-07'),
        );
    }

    public function test_the_pdf_leaves_out_cash_when_it_matches_revenue(): void
    {
        Carbon::setTestNow('2026-08-15 12:00:00');

        // Paid at delivery, so collected equals revenue and a Cash section
        // would only restate the figure above it.
        $this->sellAt('2026-07-10 06:00:00');

        $html = $this->reportHtml('2026-07');

        $this->assertStringNotContainsString('Money received', $html);
    }

    public function test_the_pdf_shows_cash_when_it_differs_from_revenue(): void
    {
        Carbon::setTestNow('2026-08-15 12:00:00');

        // Left owing, so the month billed more than it took — which is exactly
        // what the Cash line exists to say.
        $this->orders->record([
            'mobile_number' => '01700000002',
            'customer_name' => 'Karim',
            'occurred_at'   => '2026-07-10 06:00:00',
            'items'         => [[
                'catalogue_id'     => $this->cylinder->id,
                'transaction_type' => 'swap',
                'quantity'         => 1,
                'unit_price'       => 1400,
            ]],
            'is_paid' => false,
        ], $this->user->id);

        $html = $this->reportHtml('2026-07');

        $this->assertStringContainsString('Money received', $html);
    }

    public function test_the_report_breaks_sales_down_by_item(): void
    {
        Carbon::setTestNow('2026-08-15 12:00:00');

        // A second brand at a different weight. Two catalogue rows that must
        // stay apart in the breakdown rather than merging into one line.
        $bashundhara = Brand::create(['name' => 'Bashundhara', 'slug' => 'bashundhara']);
        $small = Catalogue::create([
            'type'          => 'lpg_cylinder',
            'brand_id'      => $bashundhara->id,
            'weight'        => 5,
            'is_gas'        => true,
            'is_returnable' => true,
        ]);

        app(InventoryService::class)->record([
            'catalogue_id'    => $small->id,
            'purchased_at'    => '2026-07-01',
            'filled_quantity' => 100,
            'shell_unit_cost' => 700,
            'gas_unit_cost'   => 400,
        ], $this->user->id);

        $this->sellAt('2026-07-10 06:00:00');
        $this->sellAt('2026-07-11 06:00:00');

        $this->orders->record([
            'mobile_number' => '01700000001',
            'customer_name' => 'Rahim',
            'occurred_at'   => '2026-07-12 06:00:00',
            'items'         => [[
                'catalogue_id'     => $small->id,
                'transaction_type' => 'swap',
                'quantity'         => 3,
                'unit_price'       => 700,
            ]],
            'is_paid' => true,
        ], $this->user->id);

        $items = $this->dashboard->monthlyReport('2026-07')['items'];

        $this->assertCount(2, $items);

        // Ordered by how many sold, so the busiest line leads.
        $this->assertSame('Bashundhara LPG cylinder 5kg', $items[0]['name']);
        $this->assertSame(3, $items[0]['quantity']);
        $this->assertSame(2100.0, $items[0]['revenue']);

        $this->assertSame(2, $items[1]['quantity']);
        $this->assertSame(2800.0, $items[1]['revenue']);
    }

    public function test_item_sales_split_swap_from_outright(): void
    {
        Carbon::setTestNow('2026-08-15 12:00:00');

        // A swap returns the cylinder; buying with gas keeps it.
        $this->sellAt('2026-07-10 06:00:00');

        $this->orders->record([
            'mobile_number' => '01700000001',
            'customer_name' => 'Rahim',
            'occurred_at'   => '2026-07-11 06:00:00',
            'items'         => [[
                'catalogue_id'     => $this->cylinder->id,
                'transaction_type' => 'buy_with_gas',
                'quantity'         => 2,
                'unit_price'       => 800,
                'cylinder_price'   => 1200,
            ]],
            'is_paid' => true,
        ], $this->user->id);

        $item = $this->dashboard->monthlyReport('2026-07')['items'][0];

        $this->assertSame(3, $item['quantity']);
        $this->assertSame(1, $item['swapped']);
        // Two shells left the business, which is what this column is for.
        $this->assertSame(2, $item['outright']);
    }

    public function test_item_revenue_adds_up_to_the_months_revenue(): void
    {
        Carbon::setTestNow('2026-08-15 12:00:00');

        $this->sellAt('2026-07-10 06:00:00');
        $this->sellAt('2026-07-11 06:00:00', 2000);

        $report = $this->dashboard->monthlyReport('2026-07');

        // The breakdown is the same money as page one, divided by what
        // produced it — if these drift, one of them is wrong.
        $this->assertSame(
            $report['revenue'],
            round(array_sum(array_column($report['items'], 'revenue')), 2),
        );
    }

    public function test_a_month_with_no_sales_has_no_item_breakdown(): void
    {
        Carbon::setTestNow('2026-08-15 12:00:00');

        // Nothing sold, so the PDF should not gain a blank second page.
        $this->assertSame([], $this->dashboard->monthlyReport('2026-03')['items']);
        $this->assertStringNotContainsString('Sales by item', $this->reportHtml('2026-03'));
    }

    public function test_the_item_table_reaches_the_pdf_template(): void
    {
        Carbon::setTestNow('2026-08-15 12:00:00');

        $this->sellAt('2026-07-10 06:00:00');

        $html = $this->reportHtml('2026-07');

        $this->assertStringContainsString('Sales by item', $html);
        $this->assertStringContainsString('Jamuna', $html);
        $this->assertStringContainsString('page-break', $html);
    }

    public function test_the_preview_is_admin_only(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/reports/preview')
            ->assertForbidden();
    }
}
