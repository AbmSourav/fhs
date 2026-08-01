<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Services\CustomerService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CustomerController extends Controller
{
    public function __construct(
        private readonly CustomerService $customers,
    ) {}

    public function index(): Response
    {
        return Inertia::render('customers/index', [
            'customers' => $this->customers->paginate(),
        ]);
    }

    /** One customer's trading history, newest first. */
    public function history(Customer $customer): Response
    {
        return Inertia::render('customers/history', [
            'customer' => $this->customers->presentProfile($customer),
        ]);
    }

    public function edit(Customer $customer): Response
    {
        return Inertia::render('customers/edit', [
            'customer' => $this->customers->presentForForm($customer),
        ]);
    }

    public function update(Request $request, Customer $customer): RedirectResponse
    {
        $data = $request->validate(
            $this->customers->rules($customer),
            $this->customers->messages(),
        );

        $updated = $this->customers->update($customer, $data);

        return to_route('customers.index')
            ->with('success', "{$updated->name} updated.");
    }
}
