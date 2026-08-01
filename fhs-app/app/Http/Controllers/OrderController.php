<?php

namespace App\Http\Controllers;

use App\Models\Order;
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

    /** The same form as create(), pre-filled with an existing sale. */
    public function edit(Order $order): Response
    {
        $order->load(['customer', 'items', 'payments']);

        return Inertia::render('orders/add', [
            'items'            => $this->orders->sellableItems(),
            'transactionTypes' => $this->orders->transactionTypes(),
            'order'            => $this->orders->presentForForm($order),
            // A stale link still opens the form, but read-only with the reason
            // shown, rather than 404ing on a sale that plainly exists.
            'blockedReason' => $order->editBlockedReason(),
        ]);
    }

    public function update(Request $request, Order $order): RedirectResponse
    {
        $data = $request->validate(
            $this->orders->rules(),
            $this->orders->messages(),
        );

        $updated = $this->orders->update($order, $data, $request->user()->id);

        return to_route('orders.index')
            ->with('success', "Sale updated for {$updated->customer->name}.");
    }

    /** The form for settling what a sale still owes. */
    public function pay(Order $order): Response
    {
        $order->load(['customer', 'payments']);

        return Inertia::render('orders/pay', [
            'order' => $this->orders->presentForPayment($order),
        ]);
    }

    public function storePayment(Request $request, Order $order): RedirectResponse
    {
        $data = $request->validate(
            $this->orders->paymentRules(),
            $this->orders->paymentMessages(),
        );

        // Overpayment and already-settled orders throw ValidationException,
        // which Inertia turns into form errors on the redirect back.
        $payment = $this->orders->settle($order, $data, $request->user()->id);

        $remaining = $order->fresh()->dueAmount();

        $message = $remaining > 0
            ? "Payment recorded. {$remaining} still owed."
            : "Payment recorded. {$order->customer->name}'s sale is now settled.";

        return to_route('orders.index')->with('success', $message);
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
