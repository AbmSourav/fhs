<?php

namespace App\Http\Controllers;

use App\Models\GasInventoryPurchase;
use App\Models\InventoryPurchase;
use App\Services\InventoryService;
use Illuminate\Database\Eloquent\Model;
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

    /** The same form as create(), pre-filled with an existing purchase. */
    public function edit(string $kind, int $id): Response
    {
        $purchase = $this->findPurchase($kind, $id);

        return Inertia::render('inventories/add', [
            'items'    => $this->inventory->purchasableItems(),
            'purchase' => $this->inventory->presentForForm($purchase),
            'kind'     => $kind,
            // Editing is time- and count-limited. A stale link still opens the
            // form, but read-only with the reason shown, rather than 404ing on
            // a purchase that plainly exists.
            'blockedReason' => $purchase->editBlockedReason(),
        ]);
    }

    public function update(Request $request, string $kind, int $id): RedirectResponse
    {
        $purchase = $this->findPurchase($kind, $id);

        $data = $request->validate(
            $this->inventory->rules(),
            $this->inventory->messages(),
        );

        // The service re-checks the edit rules: this is the only path that can
        // rewrite stock, so it must not depend on the form having behaved.
        $replacement = $this->inventory->edit($purchase, $data, $request->user()->id);

        $name = $replacement->catalogueItem->displayName();

        return to_route('inventories.index')->with('success', "Purchase updated for {$name}.");
    }

    /**
     * Resolve a purchase from its kind and id.
     *
     * The two purchase tables have independent id sequences, so the kind is
     * needed to know which one to look in.
     *
     * @return GasInventoryPurchase|InventoryPurchase
     */
    private function findPurchase(string $kind, int $id): Model
    {
        $model = $kind === 'plain'
            ? InventoryPurchase::query()
            : GasInventoryPurchase::query();

        $purchase = $model->find($id);

        // Superseded rows are history, not something to edit further.
        abort_if($purchase === null, 404);
        abort_unless($model->getModel()->newQuery()->current()->whereKey($id)->exists(), 404);

        return $purchase;
    }
}
