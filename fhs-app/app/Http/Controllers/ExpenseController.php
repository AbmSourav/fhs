<?php

namespace App\Http\Controllers;

use App\Models\Expense;
use App\Services\ExpenseService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ExpenseController extends Controller
{
    public function __construct(
        private readonly ExpenseService $expenses,
    ) {}

    public function index(): Response
    {
        return Inertia::render('expenses/index', [
            'expenses' => $this->expenses->paginate(),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('expenses/add', [
            'categories' => $this->expenses->categoryOptions(),
            'methods'    => $this->expenses->paymentMethodOptions(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate(
            $this->expenses->rules(),
            $this->expenses->messages(),
        );

        $expense = $this->expenses->record($data, $request->user()->id);

        return to_route('expenses.index')
            ->with('success', "Expense recorded: {$expense->description}.");
    }

    /** Reuses the add form, filled in. */
    public function edit(Expense $expense): Response
    {
        return Inertia::render('expenses/add', [
            'expense'    => $this->expenses->presentForForm($expense),
            'categories' => $this->expenses->categoryOptions(),
            'methods'    => $this->expenses->paymentMethodOptions(),
            // Explains a form that will be rejected on submit, so staff are not
            // told only after filling it in.
            'editBlockedReason' => $expense->editBlockedReason(),
        ]);
    }

    public function update(Request $request, Expense $expense): RedirectResponse
    {
        $data = $request->validate(
            $this->expenses->rules(),
            $this->expenses->messages(),
        );

        $updated = $this->expenses->update($expense, $data);

        return to_route('expenses.index')
            ->with('success', "Expense updated: {$updated->description}.");
    }

    public function destroy(Expense $expense): RedirectResponse
    {
        $description = $expense->description;

        $this->expenses->delete($expense);

        return to_route('expenses.index')
            ->with('success', "Expense deleted: {$description}.");
    }
}
