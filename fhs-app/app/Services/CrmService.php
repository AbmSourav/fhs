<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Models\Customer;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

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
                // Oldest first: the longest overdue need calling most.
                ->orderBy('last_ordered_at'),
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
        ];
    }
}
