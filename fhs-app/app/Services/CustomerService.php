<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Models\Customer;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
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
            'name'    => ['required', 'string', 'max:255'],
            'address' => ['nullable', 'string', 'max:1000'],
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
            'name' => trim($data['name']),
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

        return [
            'id'            => $customer->id,
            'name'          => $customer->name,
            'mobile_number' => $customer->mobile_number,
            'address'       => $customer->address,
            'order_count'   => (int) $customer->order_count,
            'total_spent'   => $billed,
            // What is still owed across every order that happened.
            'due_amount' => round($billed - $paid, 2),
            // withMax returns a raw string: aggregate aliases bypass the
            // model's casts, so this is converted here to match every other
            // date the frontend receives.
            'last_ordered_at' => $customer->last_ordered_at !== null
                ? Carbon::parse($customer->last_ordered_at)
                : null,
        ];
    }
}
