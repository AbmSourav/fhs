<?php

namespace App\Http\Controllers;

use App\Services\OrderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class OrderController extends Controller
{
    public function __construct(
        private readonly OrderService $orders,
    ) {}

    public function index(): Response
    {
        return Inertia::render('orders/index', [
            'orders' => $this->orders->paginate(),
        ]);
    }

    /** The form for recording a completed sale. */
    public function create(): Response
    {
        return Inertia::render('orders/add', [
            'items'            => $this->orders->sellableItems(),
            'transactionTypes' => $this->orders->transactionTypes(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate(
            $this->orders->rules(),
            $this->orders->messages(),
        );

        $order = $this->orders->record($data, $request->user()->id);

        return to_route('orders.index')
            ->with('success', "Sale recorded for {$order->customer->name}.");
    }

    /**
     * Look up a customer while the sale form is being filled in.
     *
     * Returns null rather than 404 for an unknown number: not finding someone
     * is the normal case for a new customer, not an error.
     */
    public function lookupCustomer(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'mobile_number' => ['required', 'string', 'max:32'],
        ]);

        return response()->json([
            'customer' => $this->orders->findCustomerByMobile($validated['mobile_number']),
        ]);
    }
}
