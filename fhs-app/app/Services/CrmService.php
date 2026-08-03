<?php

namespace App\Services;

use App\Enums\FollowUpOutcome;
use App\Enums\OrderStatus;
use App\Models\Customer;
use App\Models\CustomerFollowUp;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * Working out who is worth contacting.
 *
 * Distinct from the customer book, which lists everyone: these are call lists,
 * each answering one follow-up question. A customer appears because something
 * about their buying pattern warrants a call, not merely because they exist.
 */
class CrmService
{
    /**
     * How long after a purchase a cylinder customer is likely due again.
     *
     * A household gets through a cylinder in roughly this long, so it is a
     * prompt to call rather than a sign anything is wrong.
     */
    public const DEFAULT_DUE_AFTER_DAYS = 20;

    /** How many orders make someone a regular worth keeping close. */
    public const DEFAULT_REPEAT_THRESHOLD = 2;

    /** The three lists, and what each is for. */
    public const FILTERS = [
        'due'    => 'Due a refill',
        'lapsed' => 'Lapsed',
        'repeat' => 'Repeat Customers',
    ];

    /**
     * One call list.
     *
     * The three are deliberately separate rather than combined: "due a refill"
     * and "lapsed" are the same measure at different thresholds, so applying
     * both at once would only ever return the longer one's results.
     */
    public function paginate(string $filter, ?int $days = null, ?int $minOrders = null, int $perPage = 12): LengthAwarePaginator
    {
        // Failed orders never happened, so they count toward nothing.
        $happened = fn (Builder $query) => $query->where('status', '!=', OrderStatus::Failed->value);

        $query = Customer::query()
            ->withCount(['orders as order_count' => $happened])
            ->withSum(['orders as billed_total' => $happened], 'total_amount')
            ->withMax(['orders as last_ordered_at' => $happened], 'occurred_at')
            ->withSum(
                ['payments as paid_total' => fn (Builder $query) => $query->whereHas('order', $happened)],
                'amount',
            )
            // When they were last called, so a list can show who has already
            // been chased. A subquery max, not a join — joining would repeat
            // the customer once per call.
            ->withMax('followUps as last_called_at', 'called_at')
            // Someone who has never bought anything is not a follow-up: there
            // is no rhythm to have fallen out of and nothing to refill.
            ->whereHas('orders', $happened);

        $this->applyFilter($query, $filter, $days, $minOrders, $happened);

        return $query
            ->paginate($perPage)
            ->withQueryString()
            ->through(fn (Customer $customer) => $this->present($customer));
    }

    /**
     * Narrow and order the list for the chosen filter.
     *
     * Ordering is part of the filter rather than an afterthought: the most
     * overdue belong at the top of a call list, and the best customers at the
     * top of a retention one.
     */
    private function applyFilter(Builder $query, string $filter, ?int $days, ?int $minOrders, callable $happened): void
    {
        match ($filter) {
            // Constrained through whereHas rather than HAVING on the withCount
            // alias: that alias is a subquery select, not a grouped column, so
            // HAVING cannot see it.
            'repeat' => $query
                ->whereHas('orders', $happened, '>=', $minOrders ?? static::DEFAULT_REPEAT_THRESHOLD)
                ->orderByDesc('order_count')
                ->orderByDesc('last_ordered_at'),

            // 'lapsed' and 'due' are the same query at different thresholds —
            // one asks who is ready to buy, the other who has stopped.
            //
            // Expressed as "has bought, but not recently": filtering on the
            // last_ordered_at alias would not work, since a subquery select
            // cannot appear in a WHERE clause.
            default => $query
                ->whereDoesntHave('orders', fn (Builder $orders) => $happened($orders)
                    ->where('occurred_at', '>', $this->cutoffFor($filter, $days)))
                // The two lists are worked from opposite ends. 'due' starts
                // with whoever just crossed the threshold — they are closest to
                // needing a refill and most likely to buy. 'lapsed' starts with
                // the longest silent, who are furthest gone and need chasing
                // hardest.
                ->orderBy('last_ordered_at', $filter === 'due' ? 'desc' : 'asc'),
        };

        // Ties would otherwise order arbitrarily, so a list would shuffle
        // between page loads.
        $query->orderByDesc('id');
    }

    /** Nobody who has bought since this moment belongs on the list. */
    private function cutoffFor(string $filter, ?int $days): Carbon
    {
        $threshold = $days ?? match ($filter) {
            'lapsed' => CustomerService::LAPSED_AFTER_DAYS,
            default  => static::DEFAULT_DUE_AFTER_DAYS,
        };

        return now()->subDays($threshold);
    }

    /**
     * Open a follow-up the moment a call is placed.
     *
     * Written before the form is filled in, so an abandoned form still leaves
     * evidence that someone was called — the customer nobody got round to
     * writing up is exactly the one worth remembering. The outcome starts as
     * "no answer" because that is what a placed call amounts to until somebody
     * says otherwise.
     */
    public function startCall(Customer $customer, int $calledBy): CustomerFollowUp
    {
        return CustomerFollowUp::create([
            'customer_id' => $customer->id,
            'called_by'   => $calledBy,
            'outcome'     => FollowUpOutcome::NoAnswer,
            'called_at'   => now(),
        ]);
    }

    /** Validation for recording how a call went. */
    public function followUpRules(): array
    {
        return [
            'outcome' => ['required', Rule::enum(FollowUpOutcome::class)],
            'note'    => ['nullable', 'string', 'max:2000'],
            // Prefilled from when the call was placed, but editable: a call
            // made this morning may not be written up until tonight. Never
            // ahead, since it records something that already happened.
            'called_at' => ['required', 'date', 'before_or_equal:now'],
            // A promised callback is always in the future; a past date would
            // put the customer on a to-do list that is already overdue.
            'call_again_on' => ['nullable', 'date', 'after_or_equal:today'],
        ];
    }

    /** @return array<string, string> */
    public function followUpMessages(): array
    {
        return [
            'outcome.required'             => 'Choose how the call went.',
            'called_at.required'           => 'Enter when the call was made.',
            'called_at.before_or_equal'    => 'A call cannot be dated in the future.',
            'call_again_on.after_or_equal' => 'A callback date cannot be in the past.',
        ];
    }

    /**
     * Record how a call went.
     *
     * Updates the row opened when the call was placed rather than adding a
     * second one, so one call is one record.
     */
    public function recordCall(CustomerFollowUp $followUp, array $data): CustomerFollowUp
    {
        $note = trim((string) ($data['note'] ?? ''));

        $followUp->update([
            'outcome'       => $data['outcome'],
            'note'          => $note !== '' ? $note : null,
            'called_at'     => $data['called_at'],
            'call_again_on' => $data['call_again_on'] ?? null,
        ]);

        return $followUp;
    }

    /** A customer and their call history, for the follow-up form. */
    public function presentFollowUp(CustomerFollowUp $followUp): array
    {
        $customer = $followUp->customer;

        return [
            'id'        => $followUp->id,
            'outcome'   => $followUp->outcome->value,
            'note'      => $followUp->note ?? '',
            'called_at' => $followUp->called_at->toIso8601String(),
            'customer'  => [
                'id'            => $customer->id,
                'name'          => $customer->name,
                'mobile_number' => $customer->mobile_number,
                'address'       => $customer->address,
            ],
            // Earlier calls, so staff can see what was said last time before
            // writing up this one.
            'history' => $customer->followUps()
                ->where('id', '!=', $followUp->id)
                ->with('calledBy')
                ->limit(5)
                ->get()
                ->map(fn (CustomerFollowUp $past) => [
                    'id'            => $past->id,
                    'outcome_label' => $past->outcome->label(),
                    'note'          => $past->note,
                    'called_at'     => $past->called_at,
                    'called_by'     => $past->calledBy?->name,
                ])
                ->all(),
        ];
    }

    /** The outcome choices, for the form's select. */
    public function outcomeOptions(): array
    {
        return array_map(
            fn (FollowUpOutcome $outcome) => [
                'value' => $outcome->value,
                'label' => $outcome->label(),
            ],
            FollowUpOutcome::cases(),
        );
    }

    /** Validation for the filter controls. */
    public function rules(): array
    {
        return [
            'filter'     => ['nullable', 'string', 'in:'.implode(',', array_keys(static::FILTERS))],
            'days'       => ['nullable', 'integer', 'min:1', 'max:3650'],
            'min_orders' => ['nullable', 'integer', 'min:1', 'max:1000'],
        ];
    }

    /** What the filter controls should show when the page opens. */
    public function filterOptions(): array
    {
        return [
            'filters'                => static::FILTERS,
            'default_due_days'       => static::DEFAULT_DUE_AFTER_DAYS,
            'default_lapsed_days'    => CustomerService::LAPSED_AFTER_DAYS,
            'default_repeat_minimum' => static::DEFAULT_REPEAT_THRESHOLD,
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
            'id'              => $customer->id,
            'name'            => $customer->name,
            'mobile_number'   => $customer->mobile_number,
            'address'         => $customer->address,
            'order_count'     => (int) $customer->order_count,
            'total_spent'     => $billed,
            'due_amount'      => round($billed - $paid, 2),
            'last_ordered_at' => $lastOrderedAt,
            'has_lapsed'      => $lastOrderedAt !== null
                && $lastOrderedAt->diffInDays(now()) >= CustomerService::LAPSED_AFTER_DAYS,
            // How long since they last bought, which is the whole point of
            // every one of these lists.
            'days_since_order' => $lastOrderedAt !== null
                ? (int) $lastOrderedAt->diffInDays(now())
                : null,
            // Null until somebody calls them. Like last_ordered_at, this is an
            // aggregate alias and so bypasses the model's casts.
            'last_called_at' => $customer->last_called_at !== null
                ? Carbon::parse($customer->last_called_at)
                : null,
        ];
    }
}
