<?php

namespace Tests\Feature;

use App\Enums\PaymentMethod;
use App\Enums\TransactionType;
use App\Models\Brand;
use App\Models\Catalogue;
use App\Models\Customer;
use App\Models\Order;
use App\Models\User;
use App\Services\InventoryService;
use App\Services\OrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Recording a sale touches stock, cost basis, and money at once.
 *
 * These assert the derived figures rather than the rows written: stock is the
 * sum of the movement log and payment state is never stored, so checking the
 * derivation is what proves the writes were right.
 */
class OrderRecordingTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private OrderService $orders;

    private InventoryService $inventory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->orders = app(OrderService::class);
        $this->inventory = app(InventoryService::class);
    }

    private function makeCylinder(string $brandName): Catalogue
    {
        $brand = Brand::create([
            'name' => $brandName,
            'slug' => str($brandName)->slug()->toString(),
        ]);

        return Catalogue::create([
            'type'          => 'lpg_cylinder',
            'brand_id'      => $brand->id,
            'weight'        => 12.5,
            'is_gas'        => true,
            'is_returnable' => true,
        ]);
    }

    /** Stock on hand: 20 filled and 10 empty, at 900 a shell and 340 of gas. */
    private function stockUp(Catalogue $item): void
    {
        $this->inventory->record([
            'catalogue_id'    => $item->id,
            'purchased_at'    => now()->subDays(2)->toDateString(),
            'filled_quantity' => 20,
            'empty_quantity'  => 10,
            'shell_unit_cost' => 900,
            'gas_unit_cost'   => 340,
        ], $this->user->id);
    }

    private function stockOf(Catalogue $item): Catalogue
    {
        return Catalogue::withStock()->find($item->id);
    }

    /** @param  array<int, array<string, mixed>>  $items */
    private function sell(array $items, array $overrides = []): Order
    {
        return $this->orders->record([
            'mobile_number' => '01711111111',
            'customer_name' => 'Rahim',
            'occurred_at'   => now()->toDateString(),
            'items'         => $items,
            'is_paid'       => true,
            ...$overrides,
        ], $this->user->id);
    }

    public function test_a_swap_moves_gas_out_and_a_shell_back(): void
    {
        $jamuna = $this->makeCylinder('Jamuna');
        $this->stockUp($jamuna);

        $order = $this->sell([[
            'catalogue_id'     => $jamuna->id,
            'transaction_type' => 'swap',
            'quantity'         => 2,
            'unit_price'       => 1400,
        ]]);

        $stock = $this->stockOf($jamuna);

        $this->assertSame(2800.0, (float) $order->total_amount);
        $this->assertSame('paid', $order->paymentState());
        $this->assertSame(0.0, $order->dueAmount());
        // A swap trades a filled cylinder for an empty one.
        $this->assertSame(18, $stock->filledStock());
        $this->assertSame(12, $stock->emptyStock());
    }

    public function test_a_swap_is_costed_at_the_gas_price_only(): void
    {
        $jamuna = $this->makeCylinder('Jamuna');
        $this->stockUp($jamuna);

        $order = $this->sell([[
            'catalogue_id'     => $jamuna->id,
            'transaction_type' => 'swap',
            'quantity'         => 1,
            'unit_price'       => 1400,
        ]]);

        // Blending the shell in would overstate the cost of the most common
        // sale in the business by the whole price of a cylinder.
        $this->assertSame(340.0, (float) $order->items->first()->unit_cost);
    }

    public function test_an_outright_cylinder_sale_is_costed_at_gas_plus_shell(): void
    {
        $jamuna = $this->makeCylinder('Jamuna');
        $this->stockUp($jamuna);

        $order = $this->sell([[
            'catalogue_id'     => $jamuna->id,
            'transaction_type' => 'buy_with_gas',
            'quantity'         => 1,
            'unit_price'       => 3000,
        ]]);

        $stock = $this->stockOf($jamuna);

        $this->assertSame(1240.0, (float) $order->items->first()->unit_cost);
        // The shell leaves with the customer, so no empty comes back.
        $this->assertSame(19, $stock->filledStock());
        $this->assertSame(10, $stock->emptyStock());
    }

    public function test_a_bare_shell_sale_is_costed_at_the_shell_price_only(): void
    {
        $jamuna = $this->makeCylinder('Jamuna');
        $this->stockUp($jamuna);

        $order = $this->sell([[
            'catalogue_id'     => $jamuna->id,
            'transaction_type' => 'buy_empty',
            'quantity'         => 2,
            'unit_price'       => 1000,
        ]]);

        $stock = $this->stockOf($jamuna);

        $this->assertSame(900.0, (float) $order->items->first()->unit_cost);
        // Empties leave; the filled count is untouched.
        $this->assertSame(20, $stock->filledStock());
        $this->assertSame(8, $stock->emptyStock());
    }

    public function test_a_cross_brand_swap_splits_across_both_products(): void
    {
        $jamuna = $this->makeCylinder('Jamuna');
        $bashundhara = $this->makeCylinder('Bashundhara');
        $this->stockUp($jamuna);
        $this->stockUp($bashundhara);

        $this->sell([[
            'catalogue_id' => $bashundhara->id,
            // The customer handed back another brand's empty.
            'returned_catalogue_id' => $jamuna->id,
            'transaction_type'      => 'swap',
            'quantity'              => 3,
            'unit_price'            => 1450,
        ]]);

        $sold = $this->stockOf($bashundhara);
        $returned = $this->stockOf($jamuna);

        // Gas leaves the brand sold, and no shell comes back to it.
        $this->assertSame(17, $sold->filledStock());
        $this->assertSame(10, $sold->emptyStock());
        // The shells land on the brand actually handed over.
        $this->assertSame(20, $returned->filledStock());
        $this->assertSame(13, $returned->emptyStock());
    }

    public function test_a_returned_shell_is_rejected_on_a_sale_that_returns_nothing(): void
    {
        $jamuna = $this->makeCylinder('Jamuna');
        $bashundhara = $this->makeCylinder('Bashundhara');
        $this->stockUp($jamuna);

        $this->expectException(ValidationException::class);

        $this->sell([[
            'catalogue_id'          => $jamuna->id,
            'returned_catalogue_id' => $bashundhara->id,
            // Buying outright takes no shell back.
            'transaction_type' => 'buy_with_gas',
            'quantity'         => 1,
            'unit_price'       => 3000,
        ]]);
    }

    public function test_a_partial_payment_leaves_the_rest_owing(): void
    {
        $jamuna = $this->makeCylinder('Jamuna');
        $this->stockUp($jamuna);

        $order = $this->sell(
            [[
                'catalogue_id'     => $jamuna->id,
                'transaction_type' => 'buy_with_gas',
                'quantity'         => 1,
                'unit_price'       => 3000,
            ]],
            ['is_paid' => false, 'amount_paid' => 1000],
        );

        $this->assertSame(1000.0, $order->paidAmount());
        $this->assertSame(2000.0, $order->dueAmount());
        $this->assertSame('partial', $order->paymentState());
    }

    public function test_a_sale_entirely_on_credit_writes_no_payment_row(): void
    {
        $jamuna = $this->makeCylinder('Jamuna');
        $this->stockUp($jamuna);

        $order = $this->sell(
            [[
                'catalogue_id'     => $jamuna->id,
                'transaction_type' => 'swap',
                'quantity'         => 1,
                'unit_price'       => 1400,
            ]],
            ['is_paid' => false, 'amount_paid' => 0],
        );

        // A zero-amount payment would record that nothing happened.
        $this->assertSame(0, $order->payments()->count());
        $this->assertSame('due', $order->paymentState());
        $this->assertSame(1400.0, $order->dueAmount());
    }

    public function test_the_payment_method_is_recorded_on_a_paid_in_full_sale(): void
    {
        $jamuna = $this->makeCylinder('Jamuna');
        $this->stockUp($jamuna);

        $order = $this->sell(
            [[
                'catalogue_id'     => $jamuna->id,
                'transaction_type' => 'swap',
                'quantity'         => 1,
                'unit_price'       => 1400,
            ]],
            ['payment_method' => 'mobile'],
        );

        // Money changes hands on a paid-in-full sale too, so the method must
        // not be assumed to be cash.
        $this->assertSame(PaymentMethod::Mobile, $order->payments()->first()->method);
    }

    public function test_a_multi_line_order_totals_from_its_items(): void
    {
        $jamuna = $this->makeCylinder('Jamuna');
        $bashundhara = $this->makeCylinder('Bashundhara');
        $this->stockUp($jamuna);
        $this->stockUp($bashundhara);

        $order = $this->sell([
            ['catalogue_id' => $jamuna->id, 'transaction_type' => 'swap', 'quantity' => 1, 'unit_price' => 1400],
            ['catalogue_id' => $bashundhara->id, 'transaction_type' => 'buy_empty', 'quantity' => 2, 'unit_price' => 1000],
        ]);

        $this->assertCount(2, $order->items);
        $this->assertSame(3400.0, (float) $order->total_amount);
    }

    public function test_a_customer_balance_accumulates_across_orders(): void
    {
        $jamuna = $this->makeCylinder('Jamuna');
        $this->stockUp($jamuna);

        foreach ([1000, 0] as $paid) {
            $this->sell(
                [[
                    'catalogue_id'     => $jamuna->id,
                    'transaction_type' => 'buy_with_gas',
                    'quantity'         => 1,
                    'unit_price'       => 3000,
                ]],
                ['is_paid' => false, 'amount_paid' => $paid],
            );
        }

        $customer = Customer::where('mobile_number', '01711111111')->first();

        // 6000 billed, 1000 received. Summing payments through a join would
        // repeat each order's total once per payment and overstate this.
        $this->assertSame(5000.0, $customer->outstandingBalance());
    }

    public function test_an_existing_customer_is_reused_not_duplicated(): void
    {
        $jamuna = $this->makeCylinder('Jamuna');
        $this->stockUp($jamuna);

        foreach (['Rahim', 'Rahim Uddin'] as $name) {
            $this->sell(
                [[
                    'catalogue_id'     => $jamuna->id,
                    'transaction_type' => 'swap',
                    'quantity'         => 1,
                    'unit_price'       => 1400,
                ]],
                ['customer_name' => $name],
            );
        }

        $this->assertSame(1, Customer::where('mobile_number', '01711111111')->count());
    }

    public function test_customers_without_a_mobile_number_stay_separate(): void
    {
        $jamuna = $this->makeCylinder('Jamuna');
        $this->stockUp($jamuna);

        foreach (['Walk-in One', 'Walk-in Two'] as $name) {
            $this->sell(
                [[
                    'catalogue_id'     => $jamuna->id,
                    'transaction_type' => 'swap',
                    'quantity'         => 1,
                    'unit_price'       => 1400,
                ]],
                ['mobile_number' => null, 'customer_name' => $name],
            );
        }

        // A null number identifies nobody, so it must never match an earlier
        // row — otherwise every walk-in would collide with the first.
        $this->assertSame(2, Customer::whereNull('mobile_number')->count());
    }

    public function test_a_sale_can_be_recorded_with_no_customer_details(): void
    {
        $jamuna = $this->makeCylinder('Jamuna');
        $this->stockUp($jamuna);

        $order = $this->sell(
            [[
                'catalogue_id'     => $jamuna->id,
                'transaction_type' => 'swap',
                'quantity'         => 1,
                'unit_price'       => 1400,
            ]],
            ['mobile_number' => null, 'customer_name' => ''],
        );

        // customers.name is not nullable and is used as a label throughout, so
        // a blank name becomes a placeholder rather than an empty string.
        $this->assertSame('Walk-in customer', $order->customer->name);
        $this->assertNull($order->customer->mobile_number);
        $this->assertSame(1400.0, (float) $order->total_amount);
    }

    public function test_a_cross_brand_swap_names_the_returned_product(): void
    {
        $jamuna = $this->makeCylinder('Jamuna');
        $bashundhara = $this->makeCylinder('Bashundhara');
        $this->stockUp($jamuna);
        $this->stockUp($bashundhara);

        $this->sell([[
            'catalogue_id'          => $bashundhara->id,
            'returned_catalogue_id' => $jamuna->id,
            'transaction_type'      => 'swap',
            'quantity'              => 1,
            'unit_price'            => 1450,
        ]]);

        $row = $this->orders->paginate()->items()[0];

        $this->assertSame('paid', $row['payment_state']);
        $this->assertStringContainsString('Jamuna', $row['items'][0]['returned_name']);
    }

    public function test_a_same_brand_swap_does_not_name_the_returned_product(): void
    {
        $jamuna = $this->makeCylinder('Jamuna');
        $this->stockUp($jamuna);

        $this->sell([[
            'catalogue_id'          => $jamuna->id,
            'returned_catalogue_id' => $jamuna->id,
            'transaction_type'      => 'swap',
            'quantity'              => 1,
            'unit_price'            => 1400,
        ]]);

        $row = $this->orders->paginate()->items()[0];

        // Naming the same product twice would just be noise.
        $this->assertNull($row['items'][0]['returned_name']);
    }

    public function test_orders_paginate_ten_to_a_page_newest_first(): void
    {
        $jamuna = $this->makeCylinder('Jamuna');
        $this->stockUp($jamuna);

        foreach (range(1, 12) as $n) {
            $this->sell(
                [[
                    'catalogue_id'     => $jamuna->id,
                    'transaction_type' => 'swap',
                    'quantity'         => 1,
                    'unit_price'       => 1400,
                ]],
                [
                    'mobile_number' => "017000000{$n}",
                    'customer_name' => "Customer {$n}",
                    'occurred_at'   => now()->subDays($n)->toDateString(),
                ],
            );
        }

        $page = $this->orders->paginate();

        $this->assertSame(12, $page->total());
        $this->assertCount(10, $page->items());
        // Customer 1 is the most recent, having been dated one day back.
        $this->assertSame('Customer 1', $page->items()[0]['customer']['name']);
    }

    public function test_an_existing_customer_balance_is_exposed_for_the_form(): void
    {
        $jamuna = $this->makeCylinder('Jamuna');
        $this->stockUp($jamuna);

        $this->sell(
            [[
                'catalogue_id'     => $jamuna->id,
                'transaction_type' => 'buy_with_gas',
                'quantity'         => 1,
                'unit_price'       => 3000,
            ]],
            ['address' => 'Dhaka', 'is_paid' => false, 'amount_paid' => 0],
        );

        $this->assertSame([
            'name'                => 'Rahim',
            'address'             => 'Dhaka',
            'outstanding_balance' => 3000.0,
        ], $this->orders->findCustomerByMobile('01711111111'));

        $this->assertNull($this->orders->findCustomerByMobile('01999999999'));
    }

    public function test_an_invalid_line_rolls_the_whole_sale_back(): void
    {
        $jamuna = $this->makeCylinder('Jamuna');
        $this->stockUp($jamuna);

        $before = $this->stockOf($jamuna)->filledStock();

        try {
            $this->sell([
                ['catalogue_id' => $jamuna->id, 'transaction_type' => 'swap', 'quantity' => 1, 'unit_price' => 1400],
                // Invalid: an outright purchase cannot take a shell back.
                [
                    'catalogue_id'          => $jamuna->id,
                    'returned_catalogue_id' => $jamuna->id,
                    'transaction_type'      => 'buy_with_gas',
                    'quantity'              => 1,
                    'unit_price'            => 3000,
                ],
            ]);
        } catch (ValidationException) {
            // Expected.
        }

        // The first line must not survive the second one failing, or stock
        // would move for a sale that was never recorded.
        $this->assertSame($before, $this->stockOf($jamuna)->filledStock());
        $this->assertSame(0, Customer::where('mobile_number', '01711111111')->count());
    }

    public function test_correcting_a_sale_does_not_double_count_stock(): void
    {
        $jamuna = $this->makeCylinder('Jamuna');
        $this->stockUp($jamuna);

        $order = $this->sell(
            [[
                'catalogue_id'     => $jamuna->id,
                'transaction_type' => 'swap',
                'quantity'         => 5,
                'unit_price'       => 1400,
            ]],
            ['is_paid' => false, 'amount_paid' => 0],
        );

        $this->orders->update($order, [
            'mobile_number' => '01711111111',
            'customer_name' => 'Rahim',
            'occurred_at'   => now()->toDateString(),
            'items'         => [[
                'catalogue_id'     => $jamuna->id,
                'transaction_type' => 'swap',
                'quantity'         => 3,
                'unit_price'       => 1400,
            ]],
            'is_paid'     => false,
            'amount_paid' => 0,
        ], $this->user->id);

        $stock = $this->stockOf($jamuna);

        // 20 filled less 3, not less 8: the original five must be returned
        // before the corrected three are taken.
        $this->assertSame(17, $stock->filledStock());
        $this->assertSame(13, $stock->emptyStock());
        $this->assertSame(4200.0, (float) $order->fresh()->total_amount);
    }

    public function test_correcting_a_sale_replaces_its_lines(): void
    {
        $jamuna = $this->makeCylinder('Jamuna');
        $bashundhara = $this->makeCylinder('Bashundhara');
        $this->stockUp($jamuna);
        $this->stockUp($bashundhara);

        $order = $this->sell(
            [
                ['catalogue_id' => $jamuna->id, 'transaction_type' => 'swap', 'quantity' => 1, 'unit_price' => 1400],
                ['catalogue_id' => $bashundhara->id, 'transaction_type' => 'swap', 'quantity' => 1, 'unit_price' => 1400],
            ],
            ['is_paid' => false, 'amount_paid' => 0],
        );

        $this->orders->update($order, [
            'mobile_number' => '01711111111',
            'customer_name' => 'Rahim',
            'occurred_at'   => now()->toDateString(),
            // The second product is dropped entirely.
            'items' => [
                ['catalogue_id' => $jamuna->id, 'transaction_type' => 'swap', 'quantity' => 2, 'unit_price' => 1500],
            ],
            'is_paid'     => false,
            'amount_paid' => 0,
        ], $this->user->id);

        $fresh = $order->fresh(['items']);

        $this->assertCount(1, $fresh->items);
        $this->assertSame(3000.0, (float) $fresh->total_amount);
        // The dropped product's stock is returned in full.
        $this->assertSame(20, $this->stockOf($bashundhara)->filledStock());
    }

    public function test_correcting_a_sale_replaces_its_payment(): void
    {
        $jamuna = $this->makeCylinder('Jamuna');
        $this->stockUp($jamuna);

        $order = $this->sell(
            [[
                'catalogue_id'     => $jamuna->id,
                'transaction_type' => 'swap',
                'quantity'         => 1,
                'unit_price'       => 1400,
            ]],
            ['is_paid' => false, 'amount_paid' => 400],
        );

        $this->orders->update($order, [
            'mobile_number' => '01711111111',
            'customer_name' => 'Rahim',
            'occurred_at'   => now()->toDateString(),
            'items'         => [[
                'catalogue_id'     => $jamuna->id,
                'transaction_type' => 'swap',
                'quantity'         => 1,
                'unit_price'       => 1400,
            ]],
            'is_paid'     => false,
            'amount_paid' => 900,
        ], $this->user->id);

        $fresh = $order->fresh();

        // The form states the total received, so the old payment is replaced
        // rather than added to — otherwise this would read 1300.
        $this->assertSame(1, $fresh->payments()->count());
        $this->assertSame(900.0, $fresh->paidAmount());
        $this->assertSame(500.0, $fresh->dueAmount());
    }

    public function test_an_unpaid_sale_stays_editable_indefinitely(): void
    {
        $jamuna = $this->makeCylinder('Jamuna');
        $this->stockUp($jamuna);

        $order = $this->sell(
            [[
                'catalogue_id'     => $jamuna->id,
                'transaction_type' => 'swap',
                'quantity'         => 1,
                'unit_price'       => 1400,
            ]],
            ['is_paid' => false, 'amount_paid' => 0],
        );

        // Recorded a month ago, still owing.
        $order->forceFill(['created_at' => now()->subMonth()])->save();

        $this->assertTrue($order->fresh()->isEditable());
    }

    public function test_a_partly_paid_sale_stays_editable_indefinitely(): void
    {
        $jamuna = $this->makeCylinder('Jamuna');
        $this->stockUp($jamuna);

        $order = $this->sell(
            [[
                'catalogue_id'     => $jamuna->id,
                'transaction_type' => 'swap',
                'quantity'         => 1,
                'unit_price'       => 1400,
            ]],
            ['is_paid' => false, 'amount_paid' => 400],
        );

        $order->forceFill(['created_at' => now()->subMonth()])->save();

        $this->assertTrue($order->fresh()->isEditable());
    }

    public function test_a_paid_sale_closes_an_hour_after_it_was_recorded(): void
    {
        $jamuna = $this->makeCylinder('Jamuna');
        $this->stockUp($jamuna);

        $line = [[
            'catalogue_id'     => $jamuna->id,
            'transaction_type' => 'swap',
            'quantity'         => 1,
            'unit_price'       => 1400,
        ]];

        $fresh = $this->sell($line);
        $fresh->forceFill(['created_at' => now()->subMinutes(59)])->save();

        $stale = $this->sell($line, ['mobile_number' => '01722222222', 'customer_name' => 'Karim']);
        $stale->forceFill(['created_at' => now()->subMinutes(61)])->save();

        $this->assertTrue($fresh->fresh()->isEditable());
        $this->assertFalse($stale->fresh()->isEditable());
        $this->assertStringContainsString('within 1 hour', $stale->fresh()->editBlockedReason());
    }

    public function test_a_closed_sale_cannot_be_corrected(): void
    {
        $jamuna = $this->makeCylinder('Jamuna');
        $this->stockUp($jamuna);

        $order = $this->sell([[
            'catalogue_id'     => $jamuna->id,
            'transaction_type' => 'swap',
            'quantity'         => 1,
            'unit_price'       => 1400,
        ]]);

        $order->forceFill(['created_at' => now()->subHours(2)])->save();

        $before = $this->stockOf($jamuna)->filledStock();

        try {
            $this->orders->update($order->fresh(), [
                'mobile_number' => '01711111111',
                'customer_name' => 'Rahim',
                'occurred_at'   => now()->toDateString(),
                'items'         => [[
                    'catalogue_id'     => $jamuna->id,
                    'transaction_type' => 'swap',
                    'quantity'         => 9,
                    'unit_price'       => 1400,
                ]],
                'is_paid' => true,
            ], $this->user->id);

            $this->fail('A closed sale should not be correctable.');
        } catch (ValidationException) {
            // Expected.
        }

        // The rule is enforced at the write, so stock is untouched.
        $this->assertSame($before, $this->stockOf($jamuna)->filledStock());
    }

    public function test_a_sale_is_presented_for_the_edit_form(): void
    {
        $jamuna = $this->makeCylinder('Jamuna');
        $bashundhara = $this->makeCylinder('Bashundhara');
        $this->stockUp($jamuna);
        $this->stockUp($bashundhara);

        $order = $this->sell(
            [[
                'catalogue_id'          => $bashundhara->id,
                'returned_catalogue_id' => $jamuna->id,
                'transaction_type'      => 'swap',
                'quantity'              => 2,
                'unit_price'            => 1450,
            ]],
            ['address' => 'Dhaka', 'is_paid' => false, 'amount_paid' => 500, 'payment_method' => 'mobile'],
        );

        $form = $this->orders->presentForForm($order->fresh(['customer', 'items', 'payments']));

        $this->assertSame('Rahim', $form['customer_name']);
        $this->assertSame('01711111111', $form['mobile_number']);
        $this->assertSame('Dhaka', $form['address']);
        $this->assertFalse($form['is_paid']);
        $this->assertSame('500', $form['amount_paid']);
        $this->assertSame('mobile', $form['payment_method']);
        // Amounts are strings for text inputs, without a trailing ".00".
        $this->assertSame('1450', $form['items'][0]['unit_price']);
        $this->assertSame('2', $form['items'][0]['quantity']);
        $this->assertSame((string) $jamuna->id, $form['items'][0]['returned_catalogue_id']);
    }

    public function test_a_same_brand_swap_leaves_the_returned_picker_empty(): void
    {
        $jamuna = $this->makeCylinder('Jamuna');
        $this->stockUp($jamuna);

        $order = $this->sell([[
            'catalogue_id'          => $jamuna->id,
            'returned_catalogue_id' => $jamuna->id,
            'transaction_type'      => 'swap',
            'quantity'              => 1,
            'unit_price'            => 1400,
        ]]);

        $form = $this->orders->presentForForm($order->fresh(['customer', 'items', 'payments']));

        // The form treats empty as "same as sold", so naming the product again
        // would show a needless override.
        $this->assertSame('', $form['items'][0]['returned_catalogue_id']);
    }

    public function test_a_cylinder_sold_outright_is_priced_as_shell_plus_gas(): void
    {
        $jamuna = $this->makeCylinder('Jamuna');
        $this->stockUp($jamuna);

        $order = $this->sell([[
            'catalogue_id'     => $jamuna->id,
            'transaction_type' => 'buy_with_gas',
            'quantity'         => 2,
            'unit_price'       => 1000,
            'cylinder_price'   => 1400,
        ]]);

        $item = $order->items->first();

        // unit_price is what the customer pays per unit, so the two entered
        // prices are added rather than stored apart from each other.
        $this->assertSame(2400.0, (float) $item->unit_price);
        $this->assertSame(1400.0, (float) $item->cylinder_price);
        $this->assertSame(1000.0, $item->gasPrice());
        $this->assertSame(4800.0, (float) $order->total_amount);
    }

    public function test_a_swap_has_no_separate_cylinder_price(): void
    {
        $jamuna = $this->makeCylinder('Jamuna');
        $this->stockUp($jamuna);

        $order = $this->sell([[
            'catalogue_id'     => $jamuna->id,
            'transaction_type' => 'swap',
            'quantity'         => 1,
            'unit_price'       => 1400,
            // Ignored: a swap leaves no shell with the customer to charge for.
            'cylinder_price' => 900,
        ]]);

        $item = $order->items->first();

        $this->assertNull($item->cylinder_price);
        $this->assertFalse($item->hasPriceSplit());
        $this->assertSame(1400.0, (float) $item->unit_price);
    }

    public function test_the_price_split_is_shown_on_the_order_list(): void
    {
        $jamuna = $this->makeCylinder('Jamuna');
        $this->stockUp($jamuna);

        $this->sell([[
            'catalogue_id'     => $jamuna->id,
            'transaction_type' => 'buy_with_gas',
            'quantity'         => 1,
            'unit_price'       => 1000,
            'cylinder_price'   => 1400,
        ]]);

        $line = $this->orders->paginate()->items()[0]['items'][0];

        $this->assertSame(1000.0, $line['gas_price']);
        $this->assertSame(1400.0, $line['cylinder_price']);
    }

    public function test_a_swap_shows_no_price_split_on_the_order_list(): void
    {
        $jamuna = $this->makeCylinder('Jamuna');
        $this->stockUp($jamuna);

        $this->sell([[
            'catalogue_id'     => $jamuna->id,
            'transaction_type' => 'swap',
            'quantity'         => 1,
            'unit_price'       => 1400,
        ]]);

        $line = $this->orders->paginate()->items()[0]['items'][0];

        // Nothing to split, so the card shows no gas/cylinder line at all.
        $this->assertNull($line['gas_price']);
        $this->assertNull($line['cylinder_price']);
    }

    public function test_the_edit_form_splits_the_price_back_into_two_fields(): void
    {
        $jamuna = $this->makeCylinder('Jamuna');
        $this->stockUp($jamuna);

        $order = $this->sell([[
            'catalogue_id'     => $jamuna->id,
            'transaction_type' => 'buy_with_gas',
            'quantity'         => 1,
            'unit_price'       => 1000,
            'cylinder_price'   => 1400,
        ]]);

        $form = $this->orders->presentForForm($order->fresh(['customer', 'items', 'payments']));

        // The form's price field holds the gas share, matching how the two
        // were entered — not the 2400 combined total.
        $this->assertSame('1000', $form['items'][0]['unit_price']);
        $this->assertSame('1400', $form['items'][0]['cylinder_price']);
    }

    public function test_a_sale_cannot_be_dated_in_the_future(): void
    {
        $jamuna = $this->makeCylinder('Jamuna');
        $this->stockUp($jamuna);

        config(['app.admin_emails' => [strtolower($this->user->email)]]);

        $this->actingAs($this->user)
            ->post('/orders', [
                'mobile_number' => '01711111111',
                'customer_name' => 'Rahim',
                // Tomorrow: a sale that has not happened yet would move stock
                // still sitting on the shelf.
                'occurred_at' => now()->addDay()->format('Y-m-d H:i:s'),
                'items'       => [[
                    'catalogue_id'     => $jamuna->id,
                    'transaction_type' => 'swap',
                    'quantity'         => 1,
                    'unit_price'       => 1400,
                ]],
                'is_paid' => true,
            ])
            ->assertSessionHasErrors('occurred_at');

        $this->assertSame(0, Order::count());
    }

    public function test_a_sale_can_be_backdated(): void
    {
        $jamuna = $this->makeCylinder('Jamuna');
        $this->stockUp($jamuna);

        config(['app.admin_emails' => [strtolower($this->user->email)]]);

        // Deliveries made on Saturday, entered on Monday.
        $this->actingAs($this->user)
            ->post('/orders', [
                'mobile_number' => '01711111111',
                'customer_name' => 'Rahim',
                'occurred_at'   => now()->subDays(2)->format('Y-m-d H:i:s'),
                'items'         => [[
                    'catalogue_id'     => $jamuna->id,
                    'transaction_type' => 'swap',
                    'quantity'         => 1,
                    'unit_price'       => 1400,
                ]],
                'is_paid' => true,
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(1, Order::count());
    }

    public function test_a_sale_keeps_the_time_it_happened(): void
    {
        $jamuna = $this->makeCylinder('Jamuna');
        $this->stockUp($jamuna);

        $at = now()->subHours(3)->setSeconds(0);

        $order = $this->sell(
            [[
                'catalogue_id'     => $jamuna->id,
                'transaction_type' => 'swap',
                'quantity'         => 1,
                'unit_price'       => 1400,
            ]],
            ['occurred_at' => $at->format('Y-m-d H:i:s')],
        );

        // Storing only the date would put every sale at midnight, losing the
        // order in which a day's deliveries happened.
        $this->assertSame($at->format('Y-m-d H:i'), $order->occurred_at->format('Y-m-d H:i'));
        $this->assertNotSame('00:00', $order->occurred_at->format('H:i'));
    }

    public function test_the_edit_form_gives_the_time_back_to_the_input(): void
    {
        $jamuna = $this->makeCylinder('Jamuna');
        $this->stockUp($jamuna);

        $at = now()->subHours(5)->setSeconds(0);

        $order = $this->sell(
            [[
                'catalogue_id'     => $jamuna->id,
                'transaction_type' => 'swap',
                'quantity'         => 1,
                'unit_price'       => 1400,
            ]],
            ['occurred_at' => $at->format('Y-m-d H:i:s')],
        );

        $form = $this->orders->presentForForm($order->fresh(['customer', 'items', 'payments']));

        // A datetime-local input only accepts this shape; a bare date would
        // leave the field blank and reset the sale to midnight on save.
        $this->assertSame($at->format('Y-m-d\TH:i'), $form['occurred_at']);
    }

    /** A sale of 1400 with nothing yet received. */
    private function sellOnCredit(): Order
    {
        $jamuna = $this->makeCylinder('Jamuna');
        $this->stockUp($jamuna);

        return $this->sell(
            [[
                'catalogue_id'     => $jamuna->id,
                'transaction_type' => 'swap',
                'quantity'         => 1,
                'unit_price'       => 1400,
            ]],
            ['is_paid' => false, 'amount_paid' => 0],
        );
    }

    public function test_a_payment_settles_an_outstanding_balance(): void
    {
        $order = $this->sellOnCredit();

        $this->orders->settle($order, [
            'amount'  => 1400,
            'method'  => 'cash',
            'paid_at' => now()->format('Y-m-d H:i:s'),
        ], $this->user->id);

        $fresh = $order->fresh();

        $this->assertSame(0.0, $fresh->dueAmount());
        $this->assertSame('paid', $fresh->paymentState());
    }

    public function test_a_part_payment_leaves_the_remainder_owing(): void
    {
        $order = $this->sellOnCredit();

        $this->orders->settle($order, [
            'amount'  => 500,
            'method'  => 'cash',
            'paid_at' => now()->format('Y-m-d H:i:s'),
        ], $this->user->id);

        $fresh = $order->fresh();

        $this->assertSame(900.0, $fresh->dueAmount());
        $this->assertSame('partial', $fresh->paymentState());
    }

    public function test_instalments_each_leave_their_own_record(): void
    {
        $order = $this->sellOnCredit();

        foreach ([400, 600, 400] as $amount) {
            $this->orders->settle($order->fresh(), [
                'amount'  => $amount,
                'method'  => 'cash',
                'paid_at' => now()->format('Y-m-d H:i:s'),
            ], $this->user->id);
        }

        $fresh = $order->fresh();

        // Each receipt is its own event, not an adjustment of the last.
        $this->assertSame(3, $fresh->payments()->count());
        $this->assertSame(1400.0, $fresh->paidAmount());
        $this->assertSame(0.0, $fresh->dueAmount());
    }

    public function test_a_payment_cannot_exceed_what_is_owed(): void
    {
        $order = $this->sellOnCredit();

        try {
            $this->orders->settle($order, [
                'amount'  => 2000,
                'method'  => 'cash',
                'paid_at' => now()->format('Y-m-d H:i:s'),
            ], $this->user->id);

            $this->fail('Overpaying should be rejected.');
        } catch (ValidationException) {
            // Expected.
        }

        // Otherwise the balance would go negative and read as credit the
        // business does not track.
        $this->assertSame(0, $order->fresh()->payments()->count());
    }

    public function test_a_settled_sale_cannot_be_paid_again(): void
    {
        $jamuna = $this->makeCylinder('Jamuna');
        $this->stockUp($jamuna);

        $order = $this->sell([[
            'catalogue_id'     => $jamuna->id,
            'transaction_type' => 'swap',
            'quantity'         => 1,
            'unit_price'       => 1400,
        ]]);

        $this->expectException(ValidationException::class);

        $this->orders->settle($order, [
            'amount'  => 100,
            'method'  => 'cash',
            'paid_at' => now()->format('Y-m-d H:i:s'),
        ], $this->user->id);
    }

    public function test_a_payment_cannot_be_dated_in_the_future(): void
    {
        $order = $this->sellOnCredit();

        config(['app.admin_emails' => [strtolower($this->user->email)]]);

        $this->actingAs($this->user)
            ->post("/orders/pay/{$order->id}", [
                'amount'  => 500,
                'method'  => 'cash',
                'paid_at' => now()->addDay()->format('Y-m-d H:i:s'),
            ])
            ->assertSessionHasErrors('paid_at');

        $this->assertSame(0, $order->fresh()->payments()->count());
    }

    public function test_the_payment_form_prefills_the_outstanding_balance(): void
    {
        $order = $this->sellOnCredit();

        $this->orders->settle($order, [
            'amount'  => 400,
            'method'  => 'mobile',
            'paid_at' => now()->format('Y-m-d H:i:s'),
        ], $this->user->id);

        $form = $this->orders->presentForPayment($order->fresh(['customer', 'payments']));

        $this->assertSame(1400.0, $form['total_amount']);
        $this->assertSame(400.0, $form['paid_amount']);
        // Settling in full is the common case, so this prefills the field.
        $this->assertSame(1000.0, $form['due_amount']);
        $this->assertCount(1, $form['payments']);
        $this->assertSame('Mobile payment', $form['payments'][0]['method']);
    }

    public function test_every_transaction_type_is_offered_to_the_line_picker(): void
    {
        $types = collect($this->orders->transactionTypes());

        $this->assertCount(count(TransactionType::cases()), $types);
        // Only a swap takes a shell back, which is what enables cross-brand.
        $this->assertTrue($types->firstWhere('value', 'swap')['returns_shell']);
        $this->assertFalse($types->firstWhere('value', 'buy_with_gas')['returns_shell']);
    }
}
