<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Models\Customer;
use App\Models\CustomerFollowUp;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;

/**
 * Reading the customer book.
 *
 * Every figure here is derived from orders and payments rather than stored on
 * the customer: a cached total would drift the moment an order was corrected.
 */
class CustomerService
{
    /**
     * How long without an order before a customer counts as lapsed.
     *
     * Cylinders are refilled on a fairly predictable cycle, so a gap this long
     * means someone is overdue rather than simply between purchases. It says
     * nothing about why — only that they are worth following up.
     */
    public const LAPSED_AFTER_DAYS = 45;

    /**
     * How far after a sale a payment counts as a separate visit.
     *
     * Settling at delivery stamps the payment at the sale's own time, but the
     * two timestamps are written separately and can land a moment apart. This
     * tolerance keeps that from reading as a second trip to the customer.
     */
    private const SEPARATE_VISIT_MINUTES = 2;

    /**
     * Has this customer gone quiet?
     *
     * A customer who has never ordered is not lapsed: there is no rhythm to
     * have fallen out of.
     */
    private function hasLapsed(?Carbon $lastOrderedAt): bool
    {
        return $lastOrderedAt !== null
            && $lastOrderedAt->diffInDays(now()) >= static::LAPSED_AFTER_DAYS;
    }

    /**
     * Customers with their trading history, most frequent first.
     *
     * All four aggregates are subquery sums, never joins. Joining orders to
     * payments repeats an order's total once per payment row, which silently
     * inflates the totals of anyone who paid in instalments.
     */
    public function paginate(int $perPage = 10): LengthAwarePaginator
    {
        // Failed orders never happened, so they count toward nothing.
        $happened = fn (Builder $query) => $query->where('status', '!=', OrderStatus::Failed->value);

        return Customer::query()
            ->withCount(['orders as order_count' => $happened])
            ->withSum(['orders as billed_total' => $happened], 'total_amount')
            ->withMax(['orders as last_ordered_at' => $happened], 'occurred_at')
            ->withSum(
                ['payments as paid_total' => fn (Builder $query) => $query->whereHas('order', $happened)],
                'amount',
            )
            ->orderByDesc('order_count')
            // Ties on count break on recency, so the regulars a page shows stay
            // in a stable order rather than shuffling between requests.
            ->orderByDesc('last_ordered_at')
            ->orderByDesc('id')
            ->paginate($perPage)
            ->through(fn (Customer $customer) => $this->present($customer));
    }

    /**
     * Validation rules for editing a customer.
     *
     * Only identity and contact details are editable. Everything else on the
     * card — spend, balance, order count — is derived from orders and payments,
     * so there is nothing here to change it with.
     *
     * @return array<string, mixed>
     */
    public function rules(Customer $customer): array
    {
        return [
            'name'          => ['required', 'string', 'max:255'],
            'address'       => ['nullable', 'string', 'max:1000'],
            'mobile_number' => [
                'nullable',
                'string',
                'max:32',
                Rule::unique('customers', 'mobile_number')
                    ->ignore($customer->id)
                    ->withoutTrashed(),
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'mobile_number.unique' => 'Another customer already has this mobile number.',
            'name.required'        => 'Enter the customer name.',
        ];
    }

    /**
     * Update a customer's identity and contact details.
     *
     * Deliberately narrow: this cannot touch orders, payments, or anything
     * derived from them.
     */
    public function update(Customer $customer, array $data): Customer
    {
        $mobile = trim((string) ($data['mobile_number'] ?? ''));
        $address = trim((string) ($data['address'] ?? ''));

        $customer->update([
            'name'          => trim($data['name']),
            'mobile_number' => $mobile !== '' ? $mobile : null,
            'address'       => $address !== '' ? $address : null,
        ]);

        return $customer;
    }

    /** A customer in the shape the edit form expects. */
    public function presentForForm(Customer $customer): array
    {
        return [
            'id'            => $customer->id,
            'name'          => $customer->name,
            'mobile_number' => $customer->mobile_number ?? '',
            'address'       => $customer->address ?? '',
        ];
    }

    /** @return array<string, mixed> */
    private function present(Customer $customer): array
    {
        $billed = (float) ($customer->billed_total ?? 0);
        $paid = (float) ($customer->paid_total ?? 0);

        // withMax returns a raw string: aggregate aliases bypass the model's
        // casts, so this is converted here to match every other date the
        // frontend receives.
        $lastOrderedAt = $customer->last_ordered_at !== null
            ? Carbon::parse($customer->last_ordered_at)
            : null;

        return [
            'id'            => $customer->id,
            'name'          => $customer->name,
            'mobile_number' => $customer->mobile_number,
            'address'       => $customer->address,
            'order_count'   => (int) $customer->order_count,
            'total_spent'   => $billed,
            // What is still owed across every order that happened.
            'due_amount'      => round($billed - $paid, 2),
            'last_ordered_at' => $lastOrderedAt,
            // Overdue for a repeat purchase, not necessarily gone for good.
            'has_lapsed' => $this->hasLapsed($lastOrderedAt),
        ];
    }

    /**
     * One customer with everything their history page needs.
     *
     * @return array<string, mixed>
     */
    public function presentProfile(Customer $customer): array
    {
        $orders = $customer->orders()
            ->happened()
            ->with([
                'items.catalogueItem'         => fn ($query) => $query->withTrashed()->with('brand'),
                'items.returnedCatalogueItem' => fn ($query) => $query->withTrashed()->with('brand'),
                'payments',
            ])
            ->latest('occurred_at')
            // Ties on occurred_at would otherwise order arbitrarily, so a
            // backdated batch would shuffle between page loads.
            ->latest('id')
            ->get();

        $billed = (float) $orders->sum('total_amount');
        $paid = (float) $orders->sum(fn (Order $order) => $order->payments->sum('amount'));
        $lastOrderedAt = $orders->first()?->occurred_at;

        // Follow-up calls belong on the same timeline as the sales: what staff
        // want to see is the whole relationship in order — bought, chased,
        // bought again — not two lists to read side by side.
        $calls = $customer->followUps()->with('calledBy')->get();

        return [
            'id'                => $customer->id,
            'name'              => $customer->name,
            'mobile_number'     => $customer->mobile_number,
            'address'           => $customer->address,
            'order_count'       => $orders->count(),
            'total_spent'       => $billed,
            'due_amount'        => round($billed - $paid, 2),
            'last_ordered_at'   => $lastOrderedAt,
            'has_lapsed'        => $this->hasLapsed($lastOrderedAt),
            'lapsed_after_days' => static::LAPSED_AFTER_DAYS,
            'timeline'          => $this->buildTimeline($orders, $calls),
        ];
    }

    /**
     * The customer's history as a single stream of events, newest first.
     *
     * Three kinds of moment share the timeline: a sale, money collected on a
     * later visit, and a follow-up call. An order recorded as due and settled a
     * week later appears twice, so the timeline reads as what actually happened
     * rather than only the sale's current state.
     *
     * @param  Collection<int, Order>  $orders
     * @param  Collection<int, CustomerFollowUp>  $calls
     * @return array<int, array<string, mixed>>
     */
    private function buildTimeline(Collection $orders, Collection $calls): array
    {
        $entries = [];

        foreach ($orders as $order) {
            $entries[] = $this->presentTimelineEntry($order);

            foreach ($order->payments as $payment) {
                // Money handed over at delivery is part of the sale, not a
                // later event — showing it twice would just repeat the card.
                if (! $this->isLaterPayment($payment, $order)) {
                    continue;
                }

                $entries[] = $this->presentPaymentEntry($payment, $order);
            }
        }

        foreach ($calls as $call) {
            $entries[] = $this->presentCallEntry($call);
        }

        // Each kind is collected separately, so the merged list needs
        // re-sorting to read chronologically as a whole. Ids only break ties
        // within a kind — two different kinds sharing a timestamp order
        // arbitrarily but stably, which is enough.
        usort($entries, fn (array $a, array $b) => [$b['occurred_at'], $b['id']] <=> [$a['occurred_at'], $a['id']]);

        return $entries;
    }

    /**
     * A follow-up call, as its own timeline entry.
     *
     * Sits alongside the sales so a call can be read against what came before
     * and after it: whether the customer bought after being chased is the whole
     * question a call list exists to answer.
     *
     * @return array<string, mixed>
     */
    private function presentCallEntry(CustomerFollowUp $call): array
    {
        return [
            'kind'          => 'call',
            'id'            => $call->id,
            'occurred_at'   => $call->called_at,
            'outcome'       => $call->outcome->value,
            'outcome_label' => $call->outcome->label(),
            // An unanswered call left the customer exactly as they were, which
            // the entry should show rather than imply contact was made.
            'conclusive'    => $call->outcome->isConclusive(),
            'note'          => $call->note,
            'call_again_on' => $call->call_again_on,
            // Null if the user who made the call has since been removed.
            'called_by' => $call->calledBy?->name,
        ];
    }

    /**
     * Whether a payment arrived after the sale rather than at delivery.
     *
     * Recording a sale as paid stamps the payment at the moment of the sale, so
     * anything meaningfully later was collected on a separate visit.
     */
    private function isLaterPayment(Payment $payment, Order $order): bool
    {
        return $payment->paid_at->diffInMinutes($order->occurred_at, absolute: true) >= static::SEPARATE_VISIT_MINUTES;
    }

    /**
     * A payment collected after the sale, as its own timeline entry.
     *
     * @return array<string, mixed>
     */
    private function presentPaymentEntry(Payment $payment, Order $order): array
    {
        // What was still owed once this payment landed, so a run of
        // instalments reads as a balance coming down.
        $paidSoFar = (float) $order->payments
            ->filter(fn (Payment $each) => [$each->paid_at, $each->id] <= [$payment->paid_at, $payment->id])
            ->sum('amount');

        return [
            'kind'         => 'payment',
            'id'           => $payment->id,
            'occurred_at'  => $payment->paid_at,
            'amount'       => (float) $payment->amount,
            'method_label' => $payment->method->label(),
            'due_amount'   => round((float) $order->total_amount - $paidSoFar, 2),
            // Ties the receipt back to the sale it settles.
            'order_id'          => $order->id,
            'order_occurred_at' => $order->occurred_at,
        ];
    }

    /**
     * One order as a timeline entry: when, what, and what it left owing.
     *
     * The badge describes the sale as it stood at the time, not as it stands
     * today. A sale left due and settled a week later reads as "Due" here, and
     * the settlement gets its own entry further up the timeline — showing it as
     * "Paid" would erase the week the customer owed for it.
     *
     * @return array<string, mixed>
     */
    private function presentTimelineEntry(Order $order): array
    {
        $total = (float) $order->total_amount;
        $paid = (float) $order->payments
            ->reject(fn (Payment $payment) => $this->isLaterPayment($payment, $order))
            ->sum('amount');

        return [
            'kind'          => 'sale',
            'id'            => $order->id,
            'occurred_at'   => $order->occurred_at,
            'total_amount'  => $total,
            'paid_amount'   => $paid,
            'due_amount'    => round($total - $paid, 2),
            'payment_state' => match (true) {
                $paid >= $total => 'paid',
                $paid > 0       => 'partial',
                default         => 'due',
            },
            // Whether the balance left by this sale has since been collected,
            // so a historic debt is not read as one still outstanding.
            'settled_later' => $order->dueAmount() <= 0 && $paid < $total,
            'items'         => $order->items->map(fn (OrderItem $item) => [
                'id'                => $item->id,
                'display_name'      => $item->catalogueItem->displayName(),
                'transaction_label' => $item->transaction_type->label(),
                'quantity'          => $item->quantity,
                'line_total'        => (float) $item->line_total,
                // Only on a cross-brand swap: the empty handed back was a
                // different product from the one sold.
                'returned_name' => $item->returned_catalogue_id !== null
                    && $item->returned_catalogue_id !== $item->catalogue_id
                        ? $item->returnedCatalogueItem?->displayName()
                        : null,
            ])->all(),
        ];
    }
}
