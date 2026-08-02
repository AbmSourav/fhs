<?php

namespace Tests\Feature;

use App\Models\Expense;
use App\Models\User;
use App\Services\ExpenseService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Recording what the business spends outside of stock.
 *
 * An expense has no side effects — no stock movement, no customer balance — so
 * these assert the record itself and the rules around correcting it.
 */
class ExpenseRecordingTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private ExpenseService $expenses;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();

        // Expense routes are behind the `admin` gate, which reads a config list
        // of email addresses rather than a role column.
        config(['app.admin_emails' => [strtolower($this->user->email)]]);

        $this->expenses = app(ExpenseService::class);
    }

    /** @param array<string, mixed> $overrides */
    private function record(array $overrides = []): Expense
    {
        return $this->expenses->record([
            'category'       => 'transport',
            'description'    => 'Van fuel',
            'amount'         => 1200,
            'payment_method' => 'cash',
            'spent_at'       => now()->toDateTimeString(),
            ...$overrides,
        ], $this->user->id);
    }

    public function test_an_expense_is_recorded(): void
    {
        $expense = $this->record();

        $this->assertSame('Van fuel', $expense->description);
        $this->assertSame('1200.00', $expense->amount);
        $this->assertSame('transport', $expense->category->value);
        $this->assertSame($this->user->id, $expense->recorded_by);
    }

    public function test_an_expense_never_touches_stock(): void
    {
        $this->record();

        // The whole reason expenses are separate from purchases: a padlock
        // never becomes something you sell.
        $this->assertDatabaseCount('inventory_movements', 0);
    }

    public function test_optional_fields_are_stored_as_null_not_empty_strings(): void
    {
        $expense = $this->record(['paid_to' => '', 'receipt_ref' => '']);

        $this->assertNull($expense->paid_to);
        $this->assertNull($expense->receipt_ref);
    }

    public function test_expenses_are_listed_newest_first(): void
    {
        $this->record(['description' => 'Older', 'spent_at' => now()->subDays(3)->toDateTimeString()]);
        $this->record(['description' => 'Newer', 'spent_at' => now()->subDay()->toDateTimeString()]);

        $rows = $this->expenses->paginate()->items();

        $this->assertSame('Newer', $rows[0]['description']);
        $this->assertSame('Older', $rows[1]['description']);
    }

    public function test_expenses_paginate_ten_to_a_page(): void
    {
        foreach (range(1, 12) as $index) {
            $this->record(['description' => "Expense {$index}"]);
        }

        $page = $this->expenses->paginate();

        $this->assertCount(10, $page->items());
        $this->assertSame(12, $page->total());
    }

    public function test_an_expense_cannot_be_dated_in_the_future(): void
    {
        $this->actingAs($this->user)
            ->post('/expenses', [
                'category'       => 'transport',
                'description'    => 'Van fuel',
                'amount'         => 1200,
                'payment_method' => 'cash',
                'spent_at'       => now()->addDay()->toDateTimeString(),
            ])
            ->assertSessionHasErrors('spent_at');

        $this->assertDatabaseCount('expenses', 0);
    }

    public function test_an_expense_must_be_more_than_zero(): void
    {
        $this->actingAs($this->user)
            ->post('/expenses', [
                'category'       => 'transport',
                'description'    => 'Van fuel',
                'amount'         => 0,
                'payment_method' => 'cash',
                'spent_at'       => now()->toDateTimeString(),
            ])
            ->assertSessionHasErrors('amount');
    }

    public function test_an_expense_can_be_corrected_within_the_hour(): void
    {
        $expense = $this->record();

        $updated = $this->expenses->update($expense, [
            'category'       => 'transport',
            'description'    => 'Van fuel — corrected',
            'amount'         => 1500,
            'payment_method' => 'cash',
            'spent_at'       => now()->toDateTimeString(),
        ]);

        $this->assertSame('Van fuel — corrected', $updated->description);
        $this->assertSame('1500.00', $updated->amount);
    }

    public function test_an_expense_cannot_be_corrected_after_an_hour(): void
    {
        $expense = $this->record();

        // Past the window, measured from when it was recorded.
        Carbon::setTestNow(now()->addHours(2));

        $this->expectException(ValidationException::class);

        $this->expenses->update($expense, [
            'category'       => 'transport',
            'description'    => 'Too late',
            'amount'         => 1500,
            'payment_method' => 'cash',
            'spent_at'       => now()->toDateTimeString(),
        ]);
    }

    public function test_correcting_an_expense_keeps_who_recorded_it(): void
    {
        $expense = $this->record();

        $this->expenses->update($expense, [
            'category'       => 'utilities',
            'description'    => 'Electricity',
            'amount'         => 800,
            'payment_method' => 'mobile',
            'spent_at'       => now()->toDateTimeString(),
        ]);

        // A correction says what was spent, not who first entered it.
        $this->assertSame($this->user->id, $expense->fresh()->recorded_by);
    }

    public function test_an_expense_reports_whether_it_is_still_editable(): void
    {
        $this->record();

        $fresh = $this->expenses->paginate()->items()[0];
        $this->assertTrue($fresh['is_editable']);

        Carbon::setTestNow(now()->addHours(2));

        $stale = $this->expenses->paginate()->items()[0];
        $this->assertFalse($stale['is_editable']);
    }

    public function test_an_expense_can_be_deleted_long_after_the_edit_window(): void
    {
        $expense = $this->record();

        // Well past the correction window — deleting stays available.
        Carbon::setTestNow(now()->addDays(30));

        $this->actingAs($this->user)
            ->delete("/expenses/{$expense->id}")
            ->assertRedirect('/expenses');

        $this->assertSoftDeleted('expenses', ['id' => $expense->id]);
    }

    public function test_a_deleted_expense_drops_out_of_the_list(): void
    {
        $kept = $this->record(['description' => 'Kept']);
        $removed = $this->record(['description' => 'Removed']);

        $this->expenses->delete($removed);

        $rows = $this->expenses->paginate()->items();

        $this->assertCount(1, $rows);
        $this->assertSame($kept->id, $rows[0]['id']);
    }

    public function test_the_expenses_page_loads(): void
    {
        $this->record();

        $this->actingAs($this->user)
            ->get('/expenses')
            ->assertOk();
    }

    public function test_the_edit_form_is_prefilled(): void
    {
        $expense = $this->record(['paid_to' => 'Shell Station']);

        $form = $this->expenses->presentForForm($expense);

        $this->assertSame('Van fuel', $form['description']);
        // Trailing zeros are noise in a text input.
        $this->assertSame('1200', $form['amount']);
        $this->assertSame('Shell Station', $form['paid_to']);
        // Absent optional fields populate an input, so empty string not null.
        $this->assertSame('', $form['receipt_ref']);
    }

    public function test_expenses_are_admin_only(): void
    {
        $outsider = User::factory()->create();

        $this->actingAs($outsider)
            ->get('/expenses')
            ->assertForbidden();
    }
}
