<?php

namespace App\Services;

use App\Enums\MovementReason;
use App\Models\Catalogue;
use App\Models\GasInventoryPurchase;
use App\Models\InventoryMovement;
use App\Models\InventoryPurchase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Recording inventory purchases.
 *
 * A purchase is two writes that must not come apart: the purchase row itself,
 * and the movement that puts the stock on the books. Stock is only ever the sum
 * of the movement log, so a purchase without its movement is invisible stock.
 */
class InventoryService
{
    /**
     * Validation rules for recording a purchase.
     *
     * The gas-only fields are `required_if` rather than `required` because one
     * form serves both purchase kinds — which one applies is decided by the
     * chosen catalogue item, not by the client.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'catalogue_id'   => ['required', 'integer', 'exists:catalogue,id'],
            'supplier'       => ['nullable', 'string', 'max:255'],
            'invoice_ref'    => ['nullable', 'string', 'max:255'],
            'purchased_at'   => ['required', 'date'],
            'transport_cost' => ['nullable', 'numeric', 'min:0', 'max:99999999.99'],
            'other_cost'     => ['nullable', 'numeric', 'min:0', 'max:99999999.99'],

            // Gas purchases: shells and gas are counted and costed separately.
            'new_stock'       => ['boolean'],
            'filled_quantity' => ['nullable', 'integer', 'min:0', 'max:1000000'],
            'empty_quantity'  => ['nullable', 'integer', 'min:0', 'max:1000000'],
            'shell_unit_cost' => ['nullable', 'numeric', 'min:0', 'max:99999999.99'],
            'gas_unit_cost'   => ['nullable', 'numeric', 'min:0', 'max:99999999.99'],

            // Plain goods: one quantity, one cost.
            'quantity'  => ['nullable', 'integer', 'min:1', 'max:1000000'],
            'unit_cost' => ['nullable', 'numeric', 'min:0', 'max:99999999.99'],
        ];
    }

    public function messages(): array
    {
        return [
            'catalogue_id.required' => 'Choose which product was purchased.',
            'catalogue_id.exists'   => 'That product is no longer in the catalogue.',
        ];
    }

    /**
     * Record a purchase and the movement it causes.
     *
     * Dispatches on the catalogue item rather than on anything in the request:
     * whether a product is bought as gas is a fact about the product.
     *
     * @throws ValidationException
     */
    public function record(array $data, int $recordedBy): Model
    {
        $item = Catalogue::findOrFail($data['catalogue_id']);

        return DB::transaction(fn () => $item->is_gas
            ? $this->recordGasPurchase($item, $data, $recordedBy)
            : $this->recordPlainPurchase($item, $data, $recordedBy));
    }

    /**
     * A cylinder purchase: filled and empty shells counted separately.
     *
     * A refill (`new_stock` false) sends empties away and gets filled ones back,
     * so it moves stock between the two counts without acquiring any shells.
     *
     * @throws ValidationException
     */
    private function recordGasPurchase(Catalogue $item, array $data, int $recordedBy): GasInventoryPurchase
    {
        $filled = (int) ($data['filled_quantity'] ?? 0);
        $empty = (int) ($data['empty_quantity'] ?? 0);

        if ($filled === 0 && $empty === 0) {
            throw ValidationException::withMessages([
                'filled_quantity' => 'Enter how many filled or empty cylinders arrived.',
            ]);
        }

        $isRefill = ! (bool) ($data['new_stock'] ?? true);

        // A refill returns gas in shells the business already owns. Sending no
        // empties away means nothing was refilled.
        if ($isRefill && $empty === 0) {
            throw ValidationException::withMessages([
                'empty_quantity' => 'A refill exchanges empty cylinders for filled ones — enter how many empties were sent.',
            ]);
        }

        $purchase = GasInventoryPurchase::create([
            'catalogue_id'    => $item->id,
            'supplier'        => $data['supplier'] ?? null,
            'new_stock'       => ! $isRefill,
            'filled_quantity' => $filled,
            'empty_quantity'  => $empty,
            'shell_unit_cost' => $data['shell_unit_cost'] ?? 0,
            'gas_unit_cost'   => $data['gas_unit_cost'] ?? 0,
            'transport_cost'  => $data['transport_cost'] ?? 0,
            'other_cost'      => $data['other_cost'] ?? 0,
            'invoice_ref'     => $data['invoice_ref'] ?? null,
            'purchased_at'    => $data['purchased_at'],
            'recorded_by'     => $recordedBy,
        ]);

        // On a refill the empties leave the premises, so they are deducted; on
        // new stock everything that arrived is added.
        InventoryMovement::create([
            'catalogue_id'              => $item->id,
            'gas_inventory_purchase_id' => $purchase->id,
            'reason'                    => $isRefill ? MovementReason::Refill : MovementReason::Purchase,
            'filled_stock_change'       => $filled,
            'empty_stock_change'        => $isRefill ? -$empty : $empty,
            'occurred_at'               => $purchase->purchased_at,
        ]);

        return $purchase;
    }

    /**
     * A plain-goods purchase — rice and the like. No shells, so nothing moves
     * through the empty count.
     *
     * @throws ValidationException
     */
    private function recordPlainPurchase(Catalogue $item, array $data, int $recordedBy): InventoryPurchase
    {
        $quantity = (int) ($data['quantity'] ?? 0);

        if ($quantity < 1) {
            throw ValidationException::withMessages([
                'quantity' => 'Enter how many units were purchased.',
            ]);
        }

        $purchase = InventoryPurchase::create([
            'catalogue_id'   => $item->id,
            'supplier'       => $data['supplier'] ?? null,
            'quantity'       => $quantity,
            'unit_cost'      => $data['unit_cost'] ?? 0,
            'transport_cost' => $data['transport_cost'] ?? 0,
            'other_cost'     => $data['other_cost'] ?? 0,
            'invoice_ref'    => $data['invoice_ref'] ?? null,
            'purchased_at'   => $data['purchased_at'],
            'recorded_by'    => $recordedBy,
        ]);

        InventoryMovement::create([
            'catalogue_id'          => $item->id,
            'inventory_purchase_id' => $purchase->id,
            'reason'                => MovementReason::Purchase,
            'filled_stock_change'   => $quantity,
            'empty_stock_change'    => 0,
            'occurred_at'           => $purchase->purchased_at,
        ]);

        return $purchase;
    }

    /**
     * The most recent purchases from both tables, newest first, as one
     * paginated list.
     *
     * The two tables have no shared parent, so one Eloquent query cannot span
     * them. Each is capped at `$recentPerTable` rows by the database, so the
     * work stays constant no matter how long the purchase history grows.
     *
     * This is deliberately a recent-activity view, not the full archive: only
     * the newest rows from each table are reachable. Costs come from each
     * model's totalCost(), so the arithmetic lives in one place.
     */
    public function paginatePurchases(int $perPage = 12, int $recentPerTable = 10): LengthAwarePaginator
    {
        $gas = GasInventoryPurchase::query()
            // withTrashed: a purchase is a historical record, so it keeps its
            // product name even after that product leaves the catalogue.
            ->with(['catalogueItem' => fn ($query) => $query->withTrashed()->with('brand')])
            ->latest('purchased_at')
            // Ties on purchased_at would otherwise make which rows survive the
            // limit arbitrary, and so different between requests.
            ->latest('id')
            ->limit($recentPerTable)
            ->get()
            ->map(fn (GasInventoryPurchase $purchase) => $this->presentGasPurchase($purchase));

        $plain = InventoryPurchase::query()
            // withTrashed: a purchase is a historical record, so it keeps its
            // product name even after that product leaves the catalogue.
            ->with(['catalogueItem' => fn ($query) => $query->withTrashed()->with('brand')])
            ->latest('purchased_at')
            ->latest('id')
            ->limit($recentPerTable)
            ->get()
            ->map(fn (InventoryPurchase $purchase) => $this->presentPlainPurchase($purchase));

        $purchases = $gas->concat($plain)
            // `key` breaks ties so the order is total: without it, purchases
            // sharing a timestamp could swap places between page loads and a
            // row would repeat on one page and vanish from the next.
            ->sortByDesc(fn (array $row) => [$row['purchased_at']->getTimestamp(), $row['key']])
            ->values();

        $page = Paginator::resolveCurrentPage();

        return new LengthAwarePaginator(
            $purchases->forPage($page, $perPage)->values(),
            $purchases->count(),
            $perPage,
            $page,
            ['path' => Paginator::resolveCurrentPath()],
        );
    }

    /** @return array<string, mixed> */
    private function presentGasPurchase(GasInventoryPurchase $purchase): array
    {
        $kind = $purchase->catalogueItem->type->value;

        return [
            // The two tables have independent id sequences, so the product type
            // is part of the key — without it two purchases can collide.
            'key'             => "{$kind}-{$purchase->id}",
            'kind'            => $kind,
            'display_name'    => $purchase->catalogueItem->displayName(),
            'catalogue'       => $this->presentCatalogueItem($purchase->catalogueItem),
            'supplier'        => $purchase->supplier,
            'invoice_ref'     => $purchase->invoice_ref,
            'purchased_at'    => $purchase->purchased_at,
            'is_refill'       => ! $purchase->new_stock,
            'filled_quantity' => $purchase->filled_quantity,
            'empty_quantity'  => $purchase->empty_quantity,
            'shell_unit_cost' => (float) $purchase->shell_unit_cost,
            'unit_cost'       => (float) $purchase->gas_unit_cost,
            'transport_cost'  => (float) $purchase->transport_cost,
            'other_cost'      => (float) $purchase->other_cost,
            'total_cost'      => $purchase->totalCost(),
        ];
    }

    /** @return array<string, mixed> */
    private function presentPlainPurchase(InventoryPurchase $purchase): array
    {
        $kind = $purchase->catalogueItem->type->value;

        return [
            'key'          => "{$kind}-{$purchase->id}",
            'kind'         => $kind,
            'display_name' => $purchase->catalogueItem->displayName(),
            'catalogue'    => $this->presentCatalogueItem($purchase->catalogueItem),
            'supplier'     => $purchase->supplier,
            'invoice_ref'  => $purchase->invoice_ref,
            'purchased_at' => $purchase->purchased_at,
            'is_refill'       => false,
            'filled_quantity' => $purchase->quantity,
            'empty_quantity'  => 0,
            'shell_unit_cost' => 0.0,
            'unit_cost'       => (float) $purchase->unit_cost,
            'transport_cost'  => (float) $purchase->transport_cost,
            'other_cost'      => (float) $purchase->other_cost,
            'total_cost'      => $purchase->totalCost(),
        ];
    }

    /**
     * The catalogue item a purchase was for.
     *
     * Always present: the relation is loaded withTrashed, so a purchase keeps
     * its product details even after that product leaves the catalogue.
     *
     * @return array<string, mixed>
     */
    private function presentCatalogueItem(Catalogue $item): array
    {
        return [
            'id'            => $item->id,
            'name'          => $item->name,
            'type'          => $item->type->value,
            'type_label'    => $item->type->label(),
            'brand_name'    => $item->brand?->name,
            'weight'        => (float) $item->weight,
            'is_gas'        => $item->is_gas,
            'is_returnable' => $item->is_returnable,
        ];
    }

    /** Catalogue items for the purchase picker, with the flags the form needs. */
    public function purchasableItems(): Collection
    {
        return Catalogue::query()
            ->with('brand')
            ->orderBy('type')
            ->orderBy('weight')
            ->get()
            ->map(fn (Catalogue $item) => [
                'id'           => $item->id,
                'display_name' => $item->displayName(),
                // Decides which half of the form applies.
                'is_gas'        => $item->is_gas,
                'is_returnable' => $item->is_returnable,
            ]);
    }
}
