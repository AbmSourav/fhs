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
    public function history(Request $request, Customer $customer): Response
    {
        return Inertia::render('customers/history', [
            'customer' => $this->customers->presentProfile($customer),
            // Where "back" should lead. A customer opened from a call list
            // should return to that list with its filters intact, not to the
            // customer book. Rebuilt from the query string rather than taken as
            // a URL, which would be an open redirect.
            'returnTo' => $this->returnTarget($request),
        ]);
    }

    /**
     * The list this customer was opened from.
     *
     * Only two destinations are possible, so the origin is a flag rather than a
     * URL and anything unrecognised falls back to the customer book.
     *
     * @return array{label: string, href: string}
     */
    private function returnTarget(Request $request): array
    {
        if ($request->string('from')->value() !== 'crm') {
            return ['label' => 'Back to Customers', 'href' => route('customers.index')];
        }

        // Only the filter controls are carried through, so a crafted query
        // string cannot smuggle anything else onto the CRM route.
        $filters = array_filter($request->only(['filter', 'days', 'min_orders']), fn ($value) => $value !== null && $value !== '');

        return [
            'label' => 'Back to CRM',
            'href'  => route('crm').($filters !== [] ? '?'.http_build_query($filters) : ''),
        ];
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
