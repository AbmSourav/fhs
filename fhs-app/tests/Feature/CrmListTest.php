<?php

namespace Tests\Feature;

use App\Enums\FollowUpOutcome;
use App\Models\Brand;
use App\Models\Catalogue;
use App\Models\Customer;
use App\Models\CustomerFollowUp;
use App\Models\Order;
use App\Models\User;
use App\Services\CrmService;
use App\Services\CustomerService;
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

    public function test_the_refill_list_puts_the_most_recently_due_first(): void
    {
        $this->sellTo('01700000001', 'Middle', 40);
        $this->sellTo('01700000002', 'Longest', 90);
        $this->sellTo('01700000003', 'Shortest', 22);

        // Fewest days first: whoever just crossed the threshold is closest to
        // needing a refill, and most likely to buy when called.
        $this->assertSame(['Shortest', 'Middle', 'Longest'], $this->namesOn('due'));
    }

    public function test_the_lapsed_list_puts_the_longest_silent_first(): void
    {
        $this->sellTo('01700000001', 'Middle', 60);
        $this->sellTo('01700000002', 'Longest', 200);
        $this->sellTo('01700000003', 'Shortest', 46);

        // The opposite end from 'due': these are furthest gone and need
        // chasing hardest.
        $this->assertSame(['Longest', 'Middle', 'Shortest'], $this->namesOn('lapsed'));
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
        // same measure at different thresholds. 'due' reads fewest days first.
        $this->assertSame(['Just due', 'Gone quiet'], $this->namesOn('due'));
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

    public function test_placing_a_call_logs_it_before_the_form_is_filled_in(): void
    {
        $this->sellTo('01700000001', 'Overdue', 25);
        $customer = Customer::where('mobile_number', '01700000001')->first();

        $this->actingAs($this->user)
            ->post("/crm/{$customer->id}/call")
            ->assertRedirect();

        $followUp = CustomerFollowUp::query()->latest('id')->first();

        // An abandoned form still leaves evidence the call happened, which is
        // the whole reason the row is written on click.
        $this->assertSame($customer->id, $followUp->customer_id);
        $this->assertSame($this->user->id, $followUp->called_by);
        $this->assertSame(FollowUpOutcome::NoAnswer, $followUp->outcome);
        $this->assertNotNull($followUp->called_at);
    }

    public function test_writing_up_a_call_updates_the_row_rather_than_adding_one(): void
    {
        $this->sellTo('01700000001', 'Overdue', 25);
        $customer = Customer::where('mobile_number', '01700000001')->first();

        $this->actingAs($this->user)->post("/crm/{$customer->id}/call");
        $followUp = CustomerFollowUp::query()->latest('id')->first();

        $this->actingAs($this->user)
            ->post("/crm/{$customer->id}/followup/{$followUp->id}", [
                'outcome'       => 'will_buy_later',
                'note'          => 'Wants delivery after Eid',
                'called_at'     => now()->toDateTimeString(),
                'call_again_on' => now()->addWeek()->toDateString(),
            ])
            ->assertRedirect();

        // One call is one record: the placeholder is filled in, not doubled.
        $this->assertSame(1, CustomerFollowUp::count());

        $followUp->refresh();
        $this->assertSame(FollowUpOutcome::WillBuyLater, $followUp->outcome);
        $this->assertSame('Wants delivery after Eid', $followUp->note);
        $this->assertNotNull($followUp->call_again_on);
    }

    public function test_a_callback_cannot_be_promised_for_the_past(): void
    {
        $this->sellTo('01700000001', 'Overdue', 25);
        $customer = Customer::where('mobile_number', '01700000001')->first();

        $this->actingAs($this->user)->post("/crm/{$customer->id}/call");
        $followUp = CustomerFollowUp::query()->latest('id')->first();

        $this->actingAs($this->user)
            ->post("/crm/{$customer->id}/followup/{$followUp->id}", [
                'outcome'       => 'will_buy_later',
                'called_at'     => now()->toDateTimeString(),
                'call_again_on' => now()->subDay()->toDateString(),
            ])
            ->assertSessionHasErrors('call_again_on');
    }

    public function test_a_call_can_be_written_up_as_having_happened_earlier(): void
    {
        $this->sellTo('01700000001', 'Overdue', 25);
        $customer = Customer::where('mobile_number', '01700000001')->first();

        $this->actingAs($this->user)->post("/crm/{$customer->id}/call");
        $followUp = CustomerFollowUp::query()->latest('id')->first();

        // Called this morning, written up tonight.
        $this->actingAs($this->user)
            ->post("/crm/{$customer->id}/followup/{$followUp->id}", [
                'outcome'   => 'reached',
                'called_at' => now()->subHours(6)->toDateTimeString(),
            ])
            ->assertSessionHasNoErrors();

        $this->assertTrue(
            $followUp->refresh()->called_at->lessThan(now()->subHours(5)),
        );
    }

    public function test_a_call_cannot_be_dated_in_the_future(): void
    {
        $this->sellTo('01700000001', 'Overdue', 25);
        $customer = Customer::where('mobile_number', '01700000001')->first();

        $this->actingAs($this->user)->post("/crm/{$customer->id}/call");
        $followUp = CustomerFollowUp::query()->latest('id')->first();

        $this->actingAs($this->user)
            ->post("/crm/{$customer->id}/followup/{$followUp->id}", [
                'outcome'   => 'reached',
                'called_at' => now()->addDay()->toDateTimeString(),
            ])
            ->assertSessionHasErrors('called_at');
    }

    public function test_a_follow_up_belonging_to_another_customer_is_not_found(): void
    {
        $this->sellTo('01700000001', 'One', 25);
        $this->sellTo('01700000002', 'Two', 25);

        $one = Customer::where('mobile_number', '01700000001')->first();
        $two = Customer::where('mobile_number', '01700000002')->first();

        $this->actingAs($this->user)->post("/crm/{$one->id}/call");
        $followUp = CustomerFollowUp::query()->latest('id')->first();

        // The ids are both real, but they do not belong together.
        $this->actingAs($this->user)
            ->get("/crm/{$two->id}/followup/{$followUp->id}")
            ->assertNotFound();
    }

    public function test_the_list_shows_when_a_customer_was_last_called(): void
    {
        $this->sellTo('01700000001', 'Overdue', 25);
        $customer = Customer::where('mobile_number', '01700000001')->first();

        $this->assertNull($this->crm->paginate('due')->items()[0]['last_called_at']);

        $this->actingAs($this->user)->post("/crm/{$customer->id}/call");

        // Read off 'repeat' rather than 'due': calling them removes them from
        // the refill list by design, so 'due' has nothing left to assert on.
        $this->assertNotNull($this->crm->paginate('repeat', minOrders: 1)->items()[0]['last_called_at']);
    }

    public function test_calling_is_admin_only(): void
    {
        $this->sellTo('01700000001', 'Overdue', 25);
        $customer = Customer::where('mobile_number', '01700000001')->first();

        $this->actingAs(User::factory()->create())
            ->post("/crm/{$customer->id}/call")
            ->assertForbidden();

        $this->assertSame(0, CustomerFollowUp::count());
    }

    public function test_the_crm_page_is_admin_only(): void
    {
        $outsider = User::factory()->create();

        $this->actingAs($outsider)
            ->get('/crm')
            ->assertForbidden();
    }

    /** A call to a customer, a given number of days ago. */
    private function callTo(string $mobile, int $daysAgo): void
    {
        CustomerFollowUp::create([
            'customer_id' => Customer::where('mobile_number', $mobile)->value('id'),
            'called_by'   => $this->user->id,
            'outcome'     => FollowUpOutcome::NoAnswer,
            'called_at'   => now()->subDays($daysAgo),
        ]);
    }

    public function test_the_refill_list_drops_anyone_already_called_in_the_window(): void
    {
        $this->sellTo('01700000001', 'Called', 25);
        $this->sellTo('01700000002', 'Not called', 25);

        // Chased five days ago about this same refill, so calling again would
        // be the second call about one purchase.
        $this->callTo('01700000001', 5);

        $this->assertSame(['Not called'], $this->namesOn('due'));
    }

    public function test_a_call_older_than_the_window_does_not_hold_a_customer_back(): void
    {
        $this->sellTo('01700000001', 'Overdue', 40);

        // The call predates the window, so it was about an earlier refill and
        // says nothing about this one.
        $this->callTo('01700000001', 25);

        $this->assertSame(['Overdue'], $this->namesOn('due'));
    }

    public function test_the_call_exclusion_follows_the_chosen_window(): void
    {
        $this->sellTo('01700000001', 'Overdue', 40);
        $this->callTo('01700000001', 25);

        // Same call, two windows: inside 30 days it suppresses them, inside
        // 20 it is too old to count.
        $this->assertSame([], $this->namesOn('due', days: 30));
        $this->assertSame(['Overdue'], $this->namesOn('due', days: 20));
    }

    public function test_a_recent_call_does_not_hide_a_lapsed_customer(): void
    {
        $this->sellTo('01700000001', 'Long gone', 200);

        // Being called did not bring them back, so they are still lapsed —
        // dropping them would hide whoever most needs chasing.
        $this->callTo('01700000001', 2);

        $this->assertSame(['Long gone'], $this->namesOn('lapsed'));
    }

    /** A call promising a callback on a given day. */
    private function promiseCallback(string $mobile, ?int $inDays, int $calledDaysAgo = 1): void
    {
        CustomerFollowUp::create([
            'customer_id'   => Customer::where('mobile_number', $mobile)->value('id'),
            'called_by'     => $this->user->id,
            'outcome'       => FollowUpOutcome::WillBuyLater,
            'called_at'     => now()->subDays($calledDaysAgo),
            'call_again_on' => $inDays !== null ? now()->addDays($inDays)->toDateString() : null,
        ]);
    }

    public function test_the_follow_up_list_holds_only_promised_callbacks(): void
    {
        $this->sellTo('01700000001', 'Promised', 25);
        $this->sellTo('01700000002', 'Called, nothing promised', 25);
        $this->sellTo('01700000003', 'Never called', 25);

        $this->promiseCallback('01700000001', inDays: 1);
        $this->callTo('01700000002', 1);

        $this->assertSame(['Promised'], $this->namesOn('follow_up'));
    }

    public function test_the_follow_up_window_counts_forward(): void
    {
        $this->sellTo('01700000001', 'Tomorrow', 25);
        $this->sellTo('01700000002', 'Next week', 25);

        $this->promiseCallback('01700000001', inDays: 1);
        $this->promiseCallback('01700000002', inDays: 7);

        // Two days means today and tomorrow, so next week's is not yet due.
        $this->assertSame(['Tomorrow'], $this->namesOn('follow_up'));

        // Widen the window and it comes into view.
        $this->assertSame(['Tomorrow', 'Next week'], $this->namesOn('follow_up', days: 8));
    }

    public function test_a_callback_promised_for_today_is_on_the_list(): void
    {
        $this->sellTo('01700000001', 'Today', 25);
        $this->promiseCallback('01700000001', inDays: 0);

        // Even at the narrowest window: today is always inside "the next day".
        $this->assertSame(['Today'], $this->namesOn('follow_up', days: 1));
    }

    public function test_an_overdue_callback_stays_on_the_list(): void
    {
        $this->sellTo('01700000001', 'Missed', 25);

        // Promised for last week and never honoured. A missed callback does not
        // stop being owed, so no window should hide it.
        $this->promiseCallback('01700000001', inDays: -7, calledDaysAgo: 10);

        $this->assertSame(['Missed'], $this->namesOn('follow_up', days: 1));
    }

    public function test_the_follow_up_list_puts_the_soonest_first(): void
    {
        $this->sellTo('01700000001', 'Later', 25);
        $this->sellTo('01700000002', 'Overdue', 25);
        $this->sellTo('01700000003', 'Sooner', 25);

        $this->promiseCallback('01700000001', inDays: 5);
        $this->promiseCallback('01700000002', inDays: -3, calledDaysAgo: 10);
        $this->promiseCallback('01700000003', inDays: 2);

        // Anything already overdue leads, then by how soon it falls due.
        $this->assertSame(['Overdue', 'Sooner', 'Later'], $this->namesOn('follow_up', days: 10));
    }

    public function test_calling_a_customer_again_settles_the_callback(): void
    {
        $this->sellTo('01700000001', 'Promised', 25);
        $this->promiseCallback('01700000001', inDays: 1, calledDaysAgo: 3);

        $this->assertSame(['Promised'], $this->namesOn('follow_up'));

        // The promise is honoured by ringing them, not by the date arriving.
        $this->callTo('01700000001', 0);

        $this->assertSame([], $this->namesOn('follow_up'));
    }

    public function test_the_follow_up_list_carries_the_promised_date(): void
    {
        $this->sellTo('01700000001', 'Promised', 25);
        $this->promiseCallback('01700000001', inDays: 1);

        $row = $this->crm->paginate('follow_up')->items()[0];

        $this->assertNotNull($row['next_callback_on']);
        $this->assertSame(now()->addDay()->toDateString(), $row['next_callback_on']->toDateString());
    }

    public function test_a_call_appears_on_the_customers_timeline(): void
    {
        $this->sellTo('01700000001', 'Overdue', 25);
        $customer = Customer::where('mobile_number', '01700000001')->first();

        CustomerFollowUp::create([
            'customer_id' => $customer->id,
            'called_by'   => $this->user->id,
            'outcome'     => FollowUpOutcome::WillBuyLater,
            'note'        => 'Wants delivery after Eid',
            'called_at'   => now()->subDays(3),
        ]);

        $timeline = app(CustomerService::class)->presentProfile($customer)['timeline'];

        $call = collect($timeline)->firstWhere('kind', 'call');

        $this->assertNotNull($call, 'The call should share the timeline with the sale.');
        $this->assertSame('Will buy later', $call['outcome_label']);
        $this->assertSame('Wants delivery after Eid', $call['note']);
        $this->assertTrue($call['conclusive']);
        $this->assertSame($this->user->name, $call['called_by']);
    }

    public function test_the_timeline_reads_in_order_across_sales_and_calls(): void
    {
        // Bought, was chased a fortnight later, then bought again — the whole
        // point of putting calls on the same timeline as the sales.
        $this->sellTo('01700000001', 'Regular', 30);
        $customer = Customer::where('mobile_number', '01700000001')->first();

        CustomerFollowUp::create([
            'customer_id' => $customer->id,
            'called_by'   => $this->user->id,
            'outcome'     => FollowUpOutcome::Ordered,
            'called_at'   => now()->subDays(16),
        ]);

        $this->sellTo('01700000001', 'Regular', 15);

        $kinds = collect(app(CustomerService::class)->presentProfile($customer)['timeline'])
            ->pluck('kind')
            ->all();

        // Newest first: the second sale, the call that produced it, the first sale.
        $this->assertSame(['sale', 'call', 'sale'], $kinds);
    }

    public function test_an_unanswered_call_is_not_marked_as_contact(): void
    {
        $this->sellTo('01700000001', 'Overdue', 25);
        $customer = Customer::where('mobile_number', '01700000001')->first();

        CustomerFollowUp::create([
            'customer_id' => $customer->id,
            'called_by'   => $this->user->id,
            'outcome'     => FollowUpOutcome::NoAnswer,
            'called_at'   => now()->subDay(),
        ]);

        $call = collect(app(CustomerService::class)->presentProfile($customer)['timeline'])
            ->firstWhere('kind', 'call');

        $this->assertFalse($call['conclusive']);
    }

    public function test_history_opened_from_a_call_list_offers_a_way_back_to_it(): void
    {
        $this->sellTo('01700000001', 'Overdue', 25);
        $customer = Customer::where('mobile_number', '01700000001')->first();

        $this->actingAs($this->user)
            ->get("/customers/{$customer->id}/history?from=crm&filter=lapsed&days=60")
            ->assertInertia(fn ($page) => $page
                ->where('returnTo.label', 'Back to CRM')
                // The filters survive the round trip, so the list is the same
                // one they left.
                ->where('returnTo.href', route('crm').'?filter=lapsed&days=60'));
    }

    public function test_history_opened_from_the_customer_book_goes_back_there(): void
    {
        $this->sellTo('01700000001', 'Overdue', 25);
        $customer = Customer::where('mobile_number', '01700000001')->first();

        $this->actingAs($this->user)
            ->get("/customers/{$customer->id}/history")
            ->assertInertia(fn ($page) => $page
                ->where('returnTo.label', 'Back to Customers')
                ->where('returnTo.href', route('customers.index')));
    }

    public function test_the_return_link_cannot_be_pointed_off_site(): void
    {
        $this->sellTo('01700000001', 'Overdue', 25);
        $customer = Customer::where('mobile_number', '01700000001')->first();

        // Only the filter controls are carried through, so nothing a crafted
        // query string adds can redirect anyone anywhere.
        $this->actingAs($this->user)
            ->get("/customers/{$customer->id}/history?from=crm&next=https://evil.test")
            ->assertInertia(fn ($page) => $page->where('returnTo.href', route('crm')));
    }
}
