<?php

namespace App\Services;

use App\Enums\ExpenseCategory;
use App\Enums\PaymentMethod;
use App\Models\Expense;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Recording what the business spends outside of stock.
 *
 * An expense is a single row with no side effects — it touches no stock, no
 * order, and no customer balance. That is the whole reason it is separate from
 * a purchase: a padlock never becomes something you sell.
 */
class ExpenseService
{
    /** How many expenses the list page shows. */
    public const PER_PAGE = 10;

    /**
     * Validation rules for recording or correcting an expense.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'category'    => ['required', Rule::enum(ExpenseCategory::class)],
            'description' => ['required', 'string', 'max:255'],
            'amount'      => ['required', 'numeric', 'min:0.01', 'max:99999999.99'],
            'paid_to'     => ['nullable', 'string', 'max:255'],
            // The same two methods money arrives by, since it leaves the same
            // ways. Reusing the enum keeps a future cash-position report from
            // having to reconcile two vocabularies.
            'payment_method' => ['required', Rule::enum(PaymentMethod::class)],
            'receipt_ref'    => ['nullable', 'string', 'max:255'],
            // Spending is recorded after it happens, so it may be backdated but
            // never set ahead.
            'spent_at' => ['required', 'date', 'before_or_equal:now'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'category.required'        => 'Choose what kind of spending this was.',
            'description.required'     => 'Say what the money was spent on.',
            'amount.required'          => 'Enter how much was spent.',
            'amount.min'               => 'An expense must be more than zero.',
            'payment_method.required'  => 'Choose how it was paid.',
            'spent_at.required'        => 'Enter when the money was spent.',
            'spent_at.before_or_equal' => 'An expense cannot be dated in the future.',
        ];
    }

    /** The latest expenses, newest first. */
    public function paginate(int $perPage = self::PER_PAGE): LengthAwarePaginator
    {
        return Expense::query()
            ->latest('spent_at')
            // Ties on spent_at would otherwise order arbitrarily, so a batch
            // entered together would shuffle between page loads.
            ->latest('id')
            ->paginate($perPage)
            ->through(fn (Expense $expense) => $this->present($expense));
    }

    public function record(array $data, int $recordedBy): Expense
    {
        return Expense::create([
            ...$this->attributes($data),
            'recorded_by' => $recordedBy,
        ]);
    }

    /**
     * Correct an expense, within its window.
     *
     * The window is checked here rather than only in the controller so the rule
     * holds for every caller.
     *
     * @throws ValidationException
     */
    public function update(Expense $expense, array $data): Expense
    {
        if ($reason = $expense->editBlockedReason()) {
            throw ValidationException::withMessages(['description' => $reason]);
        }

        $expense->update($this->attributes($data));

        return $expense;
    }

    /**
     * Remove an expense.
     *
     * Soft delete: expenses feed reported profit, so the row stays for the
     * audit trail even though it stops counting.
     */
    public function delete(Expense $expense): void
    {
        $expense->delete();
    }

    /** An expense in the shape the edit form expects. */
    public function presentForForm(Expense $expense): array
    {
        return [
            'id'          => $expense->id,
            'category'    => $expense->category->value,
            'description' => $expense->description,
            // A string because it populates a text input; the server casts it
            // back on submit.
            'amount'         => $this->formatAmount($expense->amount),
            'paid_to'        => $expense->paid_to ?? '',
            'payment_method' => $expense->payment_method->value,
            'receipt_ref'    => $expense->receipt_ref ?? '',
            'spent_at'       => $expense->spent_at->toIso8601String(),
        ];
    }

    /** The category choices, for the form's select. */
    public function categoryOptions(): array
    {
        return array_map(
            fn (ExpenseCategory $category) => [
                'value' => $category->value,
                'label' => $category->label(),
            ],
            ExpenseCategory::cases(),
        );
    }

    /** The payment method choices, for the form's select. */
    public function paymentMethodOptions(): array
    {
        return array_map(
            fn (PaymentMethod $method) => [
                'value' => $method->value,
                'label' => $method->label(),
            ],
            PaymentMethod::cases(),
        );
    }

    /**
     * The columns an expense is written from.
     *
     * Shared by record and update so the two cannot drift. `recorded_by` is not
     * here: it says who first entered the expense and must survive a correction.
     */
    private function attributes(array $data): array
    {
        return [
            'category'       => $data['category'],
            'description'    => trim($data['description']),
            'amount'         => round((float) $data['amount'], 2),
            'paid_to'        => $this->nullIfBlank($data['paid_to'] ?? null),
            'payment_method' => $data['payment_method'],
            'receipt_ref'    => $this->nullIfBlank($data['receipt_ref'] ?? null),
            'spent_at'       => $data['spent_at'],
        ];
    }

    /** An empty input means "not given", which is null rather than "". */
    private function nullIfBlank(?string $value): ?string
    {
        $trimmed = trim((string) $value);

        return $trimmed !== '' ? $trimmed : null;
    }

    /** @return array<string, mixed> */
    private function present(Expense $expense): array
    {
        return [
            'id'             => $expense->id,
            'category'       => $expense->category->value,
            'category_label' => $expense->category->label(),
            'description'    => $expense->description,
            'amount'         => (float) $expense->amount,
            'paid_to'        => $expense->paid_to,
            'payment_method' => $expense->payment_method->value,
            'method_label'   => $expense->payment_method->label(),
            'receipt_ref'    => $expense->receipt_ref,
            'spent_at'       => $expense->spent_at,
            // False once the correction window has closed. Deleting stays
            // available regardless, so there is no matching flag for it.
            'is_editable' => $expense->isEditable(),
        ];
    }

    /** Trailing zeros are noise in a text input: 1200.00 shows as 1200. */
    private function formatAmount(mixed $value): string
    {
        return rtrim(rtrim(number_format((float) $value, 2, '.', ''), '0'), '.');
    }
}
