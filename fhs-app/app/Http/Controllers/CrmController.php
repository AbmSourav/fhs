<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\CustomerFollowUp;
use App\Services\CrmService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CrmController extends Controller
{
    public function __construct(
        private readonly CrmService $crm,
    ) {}

    /** Who is worth calling, and why. */
    public function index(Request $request): Response
    {
        $filters = $request->validate($this->crm->rules());

        $filter = $filters['filter'] ?? 'due';

        return Inertia::render('crm/index', [
            'customers' => $this->crm->paginate(
                $filter,
                $filters['days'] ?? null,
                $filters['min_orders'] ?? null,
            ),
            // Echoed back so the controls keep their values after a reload.
            'active' => [
                'filter'     => $filter,
                'days'       => $filters['days'] ?? null,
                'min_orders' => $filters['min_orders'] ?? null,
            ],
            'options' => $this->crm->filterOptions(),
        ]);
    }

    /**
     * Log that a call was placed, then send staff to write it up.
     *
     * The row is created here rather than on the form, so a call that never
     * gets written up still leaves a trace.
     */
    public function call(Request $request, Customer $customer): RedirectResponse
    {
        $followUp = $this->crm->startCall($customer, $request->user()->id);

        return to_route('crm.follow-up', [
            'customer' => $customer->id,
            'followUp' => $followUp->id,
        ]);
    }

    /** The form for writing up a call. */
    public function followUp(Customer $customer, CustomerFollowUp $followUp): Response
    {
        abort_unless($followUp->customer_id === $customer->id, 404);

        return Inertia::render('crm/follow-up', [
            'followUp' => $this->crm->presentFollowUp($followUp),
            'outcomes' => $this->crm->outcomeOptions(),
        ]);
    }

    public function storeFollowUp(Request $request, Customer $customer, CustomerFollowUp $followUp): RedirectResponse
    {
        abort_unless($followUp->customer_id === $customer->id, 404);

        $data = $request->validate(
            $this->crm->followUpRules(),
            $this->crm->followUpMessages(),
        );

        $this->crm->recordCall($followUp, $data);

        // Back to the list they came from, filters intact, so the next customer
        // can be called straight away. Only the query string is carried over —
        // taking a whole URL from the request would be an open redirect.
        $query = $request->string('filters')->trim()->value();

        return redirect()
            ->to(route('crm').($query !== '' ? '?'.ltrim($query, '?') : ''))
            ->with('success', "Call to {$customer->name} recorded.");
    }
}
