<?php

namespace App\Services;

use App\Enums\MovementReason;
use App\Enums\PaymentMethod;
use App\Enums\TransactionType;
use App\Models\Catalogue;
use App\Models\Customer;
use App\Models\InventoryMovement;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Recording completed sales.
 *
 * A sale is several writes that must not come apart: the customer, the order,
 * its line items, the stock movements they cause, and any payment taken. Stock
 * is only ever the sum of the movement log, so an order without its movements
 * is stock that silently never left the premises.
 */
class OrderService
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // A sale can be recorded with nothing known about the buyer — a
            // walk-in who gives no details still has to be recorded.
            'mobile_number' => ['nullable', 'string', 'max:32'],
            'customer_name' => ['nullable', 'string', 'max:255'],
            'address'       => ['nullable', 'string', 'max:1000'],

            // Sales are recorded after the fact, so the date may be backdated
            // but never set ahead: a sale that has not happened yet would move
            // stock that is still on the shelf.
            'occurred_at' => ['required', 'date', 'before_or_equal:now'],

            'items'                    => ['required', 'array', 'min:1'],
            'items.*.catalogue_id'     => ['required', 'integer', 'exists:catalogue,id'],
            'items.*.transaction_type' => ['required', Rule::enum(TransactionType::class)],
            'items.*.quantity'         => ['required', 'integer', 'min:1', 'max:100000'],
            'items.*.unit_price'       => ['required', 'numeric', 'min:0', 'max:99999999.99'],
            // Buying a cylinder outright charges for the shell and the gas at
            // once, so the two are entered separately and unit_price carries
            // the gas share. Absent on every other kind of sale.
            'items.*.cylinder_price' => ['nullable', 'numeric', 'min:0', 'max:99999999.99'],
            // Set only when the customer handed back a different product than
            // the one sold — a cross-brand swap.
            'items.*.returned_catalogue_id' => ['nullable', 'integer', 'exists:catalogue,id'],

            // Off means the sale was not paid in full; `amount_paid` then says
            // how much was received, zero included.
            'is_paid'        => ['boolean'],
            'amount_paid'    => ['nullable', 'numeric', 'min:0', 'max:99999999.99'],
            'payment_method' => ['nullable', Rule::enum(PaymentMethod::class)],
        ];
    }

    public function messages(): array
    {
        return [
            'occurred_at.before_or_equal' => 'A sale cannot be dated in the future.',
            'items.required'              => 'Add at least one product to the order.',
            'items.*.unit_price.required' => 'Enter a price for each product.',
        ];
    }

    /**
     * Record a completed sale.
     *
     * @throws ValidationException
     */
    public function record(array $data, int $recordedBy): Order
    {
        return DB::transaction(function () use ($data, $recordedBy) {
            // Inside the transaction: a customer created for a sale that then
            // fails validation must not survive.
            $name = trim((string) ($data['customer_name'] ?? ''));

            $customer = Customer::findOrCreateForSale($data['mobile_number'] ?? null, [
                // customers.name is not nullable, and it is used as a label
                // throughout. A placeholder here beats a null that every
                // display site would have to guard against.
                'name'    => $name !== '' ? $name : 'Walk-in customer',
                'address' => $data['address'] ?? null,
            ]);

            $order = Order::create([
                'customer_id'  => $customer->id,
                'user_id'      => $recordedBy,
                'total_amount' => 0,
                'occurred_at'  => $data['occurred_at'],
            ]);

            foreach ($data['items'] as $line) {
                $this->addLine($order, $line);
            }

            // total_amount is a denormalization of SUM(line_total), so it is
            // recalculated in the same transaction that wrote the lines.
            $order->recalculateTotal();

            $this->recordPayment($order, $data, $recordedBy);

            return $order->fresh(['customer', 'items']);
        });
    }

    /**
     * Correct a recorded sale.
     *
     * The order keeps its identity, but its lines and stock movements are
     * rebuilt: the old movements are reversed and new ones written, so stock
     * reflects only the corrected figures while the ledger still shows both the
     * mistake and the fix.
     *
     * @throws ValidationException
     */
    public function update(Order $order, array $data, int $recordedBy): Order
    {
        // Re-checked here rather than trusting the controller: this is the only
        // path that can rewrite stock and money, so the rule belongs at the
        // write.
        if ($reason = $order->editBlockedReason()) {
            throw ValidationException::withMessages(['items' => $reason]);
        }

        return DB::transaction(function () use ($order, $data, $recordedBy) {
            $name = trim((string) ($data['customer_name'] ?? ''));

            $customer = Customer::findOrCreateForSale($data['mobile_number'] ?? null, [
                'name'    => $name !== '' ? $name : 'Walk-in customer',
                'address' => $data['address'] ?? null,
            ]);

            // Reverse every movement this order caused, then delete the lines.
            // The movements stay as history; the lines do not, because they are
            // about to be rewritten and an order item has no meaning once its
            // order no longer claims it.
            foreach ($order->movements as $movement) {
                $movement->reverse('Superseded by a correction');
            }

            $order->items()->delete();

            $order->update([
                'customer_id' => $customer->id,
                'occurred_at' => $data['occurred_at'],
            ]);

            // Relations were loaded before the delete, so they must be dropped
            // or addLine would append to a stale collection.
            $order->refresh();

            foreach ($data['items'] as $line) {
                $this->addLine($order, $line);
            }

            $order->recalculateTotal();

            // Payments are replaced rather than adjusted: the form states what
            // was received in total, not what changed.
            $order->payments()->delete();
            $this->recordPayment($order->fresh(), $data, $recordedBy);

            return $order->fresh(['customer', 'items']);
        });
    }

    /**
     * Validation rules for settling an outstanding balance.
     *
     * @return array<string, mixed>
     */
    public function paymentRules(): array
    {
        return [
            'amount'  => ['required', 'numeric', 'min:0.01', 'max:99999999.99'],
            'method'  => ['required', Rule::enum(PaymentMethod::class)],
            'paid_at' => ['required', 'date', 'before_or_equal:now'],
        ];
    }

    public function paymentMessages(): array
    {
        return [
            'amount.min'              => 'Enter how much was received.',
            'paid_at.before_or_equal' => 'A payment cannot be dated in the future.',
        ];
    }

    /**
     * Record money received against an order.
     *
     * Appends a payment rather than adjusting one: each receipt is its own
     * event, so a customer paying in instalments leaves a record of every one.
     *
     * @throws ValidationException
     */
    public function settle(Order $order, array $data, int $receivedBy): Payment
    {
        $due = $order->dueAmount();

        if ($due <= 0) {
            throw ValidationException::withMessages([
                'amount' => 'This sale is already paid in full.',
            ]);
        }

        $amount = round((float) $data['amount'], 2);

        // Overpaying would make the balance negative and read as credit the
        // business does not track.
        if ($amount > $due) {
            throw ValidationException::withMessages([
                'amount' => "That is more than the {$due} still owed on this sale.",
            ]);
        }

        return Payment::create([
            'order_id'    => $order->id,
            'amount'      => $amount,
            'method'      => $data['method'],
            'received_by' => $receivedBy,
            'paid_at'     => $data['paid_at'],
        ]);
    }

    /**
     * An order in the shape the payment form expects.
     *
     * @return array<string, mixed>
     */
    public function presentForPayment(Order $order): array
    {
        return [
            'id'       => $order->id,
            'customer' => [
                'name'          => $order->customer->name,
                'mobile_number' => $order->customer->mobile_number,
            ],
            'occurred_at'  => $order->occurred_at,
            'total_amount' => (float) $order->total_amount,
            'paid_amount'  => $order->paidAmount(),
            // Prefills the amount field: settling in full is the common case.
            'due_amount' => $order->dueAmount(),
            'payments'   => $order->payments->map(fn (Payment $payment) => [
                'id'      => $payment->id,
                'amount'  => (float) $payment->amount,
                'method'  => $payment->method->label(),
                'paid_at' => $payment->paid_at,
            ])->all(),
        ];
    }

    /**
     * Add one product to an order, with the stock movements it causes.
     *
     * @throws ValidationException
     */
    private function addLine(Order $order, array $line): void
    {
        $item = Catalogue::findOrFail($line['catalogue_id']);
        $type = TransactionType::from((string) $line['transaction_type']);

        $returnedId = $this->resolveReturnedItem($item, $type, $line);

        $quantity = (int) $line['quantity'];
        $gasPrice = (float) $line['unit_price'];

        // Only an outright cylinder purchase prices the shell separately; on
        // anything else a cylinder price would describe nothing.
        $cylinderPrice = $type->includesShell() && isset($line['cylinder_price'])
            ? (float) $line['cylinder_price']
            : null;

        // unit_price is what the customer pays per unit, so the two entered
        // prices are added rather than stored apart from each other.
        $unitPrice = round($gasPrice + (float) $cylinderPrice, 2);

        $orderItem = OrderItem::create([
            'order_id'              => $order->id,
            'catalogue_id'          => $item->id,
            'returned_catalogue_id' => $returnedId,
            'transaction_type'      => $type,
            'quantity'              => $quantity,
            'unit_price'            => $unitPrice,
            'cylinder_price'        => $cylinderPrice,
            // Weighted-average cost at sale time. Frozen here so a later price
            // change cannot rewrite this order's margin.
            'unit_cost'  => $this->costBasisFor($item, $type),
            'line_total' => round($unitPrice * $quantity, 2),
        ]);

        // stockChange() is keyed by catalogue id: one entry normally, two when
        // the customer swapped in another brand's empty.
        foreach ($orderItem->stockChange() as $catalogueId => $change) {
            InventoryMovement::create([
                'catalogue_id' => $catalogueId,
                'order_id'     => $order->id,
                'reason'       => $type === TransactionType::Swap
                    ? MovementReason::Swap
                    : MovementReason::Sale,
                'filled_stock_change' => $change['filled'],
                'empty_stock_change'  => $change['empty'],
                'occurred_at'         => $order->occurred_at,
            ]);
        }
    }

    /**
     * The product whose empty came back, when it differs from what was sold.
     *
     * @throws ValidationException
     */
    private function resolveReturnedItem(Catalogue $item, TransactionType $type, array $line): ?int
    {
        if (empty($line['returned_catalogue_id'])) {
            return null;
        }

        // Only a swap takes a shell back; anything else has nothing to return.
        if ($type->stockChangePerUnit()['empty'] <= 0) {
            throw ValidationException::withMessages([
                'items' => 'Only a swap can take an empty cylinder back.',
            ]);
        }

        $returned = Catalogue::find($line['returned_catalogue_id']);

        if ($returned === null || ! $returned->is_returnable) {
            throw ValidationException::withMessages([
                'items' => 'An empty cylinder can only be returned for a returnable product.',
            ]);
        }

        return $returned->id;
    }

    /**
     * Weighted-average cost for what this line actually consumed.
     *
     * Gas and shell are averaged separately because they are sold separately: a
     * swap consumes gas only, so charging it the shell cost too would overstate
     * the cost of the most common transaction by the whole price of a cylinder.
     */
    private function costBasisFor(Catalogue $item, TransactionType $type): float
    {
        if (! $item->is_gas) {
            return $this->plainAverageCost($item);
        }

        $gas = $type->includesGas() ? $this->gasAverageCost($item) : 0.0;
        $shell = $type->includesShell() ? $this->shellAverageCost($item) : 0.0;

        return round($gas + $shell, 2);
    }

    private function plainAverageCost(Catalogue $item): float
    {
        $row = $item->purchases()
            ->selectRaw('SUM(unit_cost * quantity) as cost, SUM(quantity) as qty')
            ->first();

        return $this->divide((float) $row?->cost, (int) $row?->qty);
    }

    private function gasAverageCost(Catalogue $item): float
    {
        // Only filled cylinders carry gas, so empties must not dilute it.
        $row = $item->gasPurchases()
            ->where('filled_quantity', '>', 0)
            ->selectRaw('SUM(gas_unit_cost * filled_quantity) as cost, SUM(filled_quantity) as qty')
            ->first();

        return $this->divide((float) $row?->cost, (int) $row?->qty);
    }

    private function shellAverageCost(Catalogue $item): float
    {
        // Swaps acquire no shells, so they are excluded — including them would
        // divide by cylinders that were never bought.
        $row = $item->gasPurchases()
            ->whereNull('swap_catalogue_id')
            ->selectRaw('SUM(shell_unit_cost * (filled_quantity + empty_quantity)) as cost, SUM(filled_quantity + empty_quantity) as qty')
            ->first();

        return $this->divide((float) $row?->cost, (int) $row?->qty);
    }

    /** Nothing purchased yet means no cost basis, not a division by zero. */
    private function divide(float $cost, int $quantity): float
    {
        return $quantity > 0 ? round($cost / $quantity, 2) : 0.0;
    }

    /**
     * Record what was received, if anything.
     *
     * A fully unpaid sale writes no payment row at all: the balance is derived
     * from what is missing, so a zero-amount row would say nothing.
     */
    private function recordPayment(Order $order, array $data, int $recordedBy): void
    {
        $isPaid = (bool) ($data['is_paid'] ?? true);

        $amount = $isPaid
            ? (float) $order->total_amount
            : (float) ($data['amount_paid'] ?? 0);

        if ($amount <= 0) {
            return;
        }

        Payment::create([
            'order_id'    => $order->id,
            'amount'      => min($amount, (float) $order->total_amount),
            'method'      => $data['payment_method'] ?? PaymentMethod::Cash->value,
            'received_by' => $recordedBy,
            'paid_at'     => $order->occurred_at,
        ]);
    }

    /** Recent orders, newest first. */
    public function paginate(int $perPage = 10): LengthAwarePaginator
    {
        return Order::query()
            ->with([
                'customer',
                'items.catalogueItem'         => fn ($query) => $query->withTrashed()->with('brand'),
                'items.returnedCatalogueItem' => fn ($query) => $query->withTrashed()->with('brand'),
            ])
            ->withSum('payments as paid_total', 'amount')
            ->latest('occurred_at')
            // Ties on occurred_at would otherwise make paging unstable.
            ->latest('id')
            ->paginate($perPage)
            ->through(fn (Order $order) => $this->present($order));
    }

    /** @return array<string, mixed> */
    private function present(Order $order): array
    {
        $paid = (float) ($order->paid_total ?? 0);
        $total = (float) $order->total_amount;

        return [
            'id'          => $order->id,
            'occurred_at' => $order->occurred_at,
            // A paid sale closes after an hour; an unpaid one stays open.
            // Derived from the loaded sum rather than calling isEditable(),
            // which would query payments once per row.
            'is_editable' => $paid < $total
                || $order->created_at->addHours(Order::PAID_EDIT_WINDOW_HOURS)->isFuture(),
            'customer' => [
                'id'            => $order->customer->id,
                'name'          => $order->customer->name,
                'mobile_number' => $order->customer->mobile_number,
            ],
            'total_amount' => $total,
            'paid_amount'  => $paid,
            'due_amount'   => round($total - $paid, 2),
            // Derived, never stored: an order can be complete but unpaid.
            'payment_state' => match (true) {
                $paid >= $total => 'paid',
                $paid > 0       => 'partial',
                default         => 'due',
            },
            'items' => $order->items->map(fn (OrderItem $item) => [
                'id'                => $item->id,
                'display_name'      => $item->catalogueItem->displayName(),
                'transaction_type'  => $item->transaction_type->value,
                'transaction_label' => $item->transaction_type->label(),
                // Only on a cross-brand swap: the empty handed back was a
                // different product from the one sold.
                'returned_name' => $item->returned_catalogue_id !== null
                    && $item->returned_catalogue_id !== $item->catalogue_id
                        ? $item->returnedCatalogueItem?->displayName()
                        : null,
                'quantity'   => $item->quantity,
                'unit_price' => (float) $item->unit_price,
                // Set only when the shell and gas were priced separately, so
                // the card can show what each part was charged at.
                'cylinder_price' => $item->hasPriceSplit() ? (float) $item->cylinder_price : null,
                'gas_price'      => $item->hasPriceSplit() ? $item->gasPrice() : null,
                'line_total'     => (float) $item->line_total,
            ])->all(),
        ];
    }

    /**
     * A recorded sale in the shape the add/edit form expects.
     *
     * Field names match the form's, so the same component serves both — the
     * only difference is whether the values start empty.
     *
     * @return array<string, mixed>
     */
    public function presentForForm(Order $order): array
    {
        $paid = $order->paidAmount();

        return [
            'id'            => $order->id,
            'mobile_number' => $order->customer->mobile_number ?? '',
            'customer_name' => $order->customer->name,
            'address'       => $order->customer->address ?? '',
            // The shape a datetime-local input requires. The stored value keeps
            // its seconds; toDateString() would drop the time entirely and
            // silently reset the sale to midnight on save.
            'occurred_at' => $order->occurred_at->format('Y-m-d\TH:i'),
            'items'       => $order->items->map(fn (OrderItem $item) => [
                'catalogue_id'     => (string) $item->catalogue_id,
                'transaction_type' => $item->transaction_type->value,
                // Empty unless the customer handed back another brand, which
                // is what the form's picker treats as "same as sold".
                'returned_catalogue_id' => $item->returned_catalogue_id !== null
                    && $item->returned_catalogue_id !== $item->catalogue_id
                        ? (string) $item->returned_catalogue_id
                        : '',
                'quantity' => (string) $item->quantity,
                // The form's price field holds the gas share when the shell is
                // priced separately, matching how the two are entered.
                'unit_price' => $this->formatAmount(
                    $item->hasPriceSplit() ? $item->gasPrice() : $item->unit_price,
                ),
                'cylinder_price' => $item->hasPriceSplit()
                    ? $this->formatAmount($item->cylinder_price)
                    : '',
            ])->all(),
            'is_paid'        => $order->isFullyPaid(),
            'amount_paid'    => $paid > 0 ? $this->formatAmount($paid) : '',
            'payment_method' => $order->payments->first()?->method->value
                ?? PaymentMethod::Cash->value,
        ];
    }

    /**
     * An amount as the form should show it.
     *
     * Trailing zeros are only stripped from the fractional part — trimming the
     * string outright would turn 1200 into 12.
     */
    private function formatAmount(mixed $value): string
    {
        return rtrim(rtrim(number_format((float) $value, 2, '.', ''), '0'), '.');
    }

    /** Products that can be sold, with what the form needs to shape each line. */
    public function sellableItems(): Collection
    {
        return Catalogue::query()
            ->with('brand')
            ->orderBy('type')
            ->orderBy('weight')
            ->get()
            ->map(fn (Catalogue $item) => [
                'id'            => $item->id,
                'display_name'  => $item->displayName(),
                'is_gas'        => $item->is_gas,
                'is_returnable' => $item->is_returnable,
            ]);
    }

    /** Transaction types for the line picker. */
    public function transactionTypes(): array
    {
        return array_map(
            fn (TransactionType $type) => [
                'value'         => $type->value,
                'label'         => $type->label(),
                'returns_shell' => $type->stockChangePerUnit()['empty'] > 0,
                // The customer keeps the shell, so it is priced separately
                // from the gas inside it.
                'sells_shell' => $type->includesShell(),
            ],
            TransactionType::cases(),
        );
    }

    /** An existing customer's details, for auto-filling the sale form. */
    public function findCustomerByMobile(string $mobileNumber): ?array
    {
        $customer = Customer::query()
            ->where('mobile_number', trim($mobileNumber))
            ->first();

        if ($customer === null) {
            return null;
        }

        return [
            'name'                => $customer->name,
            'address'             => $customer->address,
            'outstanding_balance' => $customer->outstandingBalance(),
        ];
    }
}
