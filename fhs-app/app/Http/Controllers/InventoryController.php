<?php

namespace App\Http\Controllers;

use App\Services\InventoryService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class InventoryController extends Controller
{
    public function __construct(
        private readonly InventoryService $inventory,
    ) {}

    public function index(): Response
    {
        return Inertia::render('inventories/index', [
            'purchases' => $this->inventory->paginatePurchases(),
        ]);
    }

    /** The form for recording a new purchase. */
    public function create(): Response
    {
        return Inertia::render('inventories/add', [
            'items' => $this->inventory->purchasableItems(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate(
            $this->inventory->rules(),
            $this->inventory->messages(),
        );

        // Quantity checks throw ValidationException, which Inertia turns into
        // form errors on the redirect back.
        $purchase = $this->inventory->record($data, $request->user()->id);

        $name = $purchase->catalogueItem->displayName();

        return back()->with('success', "Purchase recorded for {$name}.");
    }
}
