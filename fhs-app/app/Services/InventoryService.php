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
            'catalogue_id' => ['required', 'integer', 'exists:catalogue,id'],
            'supplier'     => ['nullable', 'string', 'max:255'],
            'invoice_ref'  => ['nullable', 'string', 'max:255'],
            // Purchases are recorded after the goods arrive, so the date may be
            // backdated but never set ahead: stock that has not been delivered
            // yet must not count toward what is on the shelf.
            'purchased_at'   => ['required', 'date', 'before_or_equal:today'],
            'transport_cost' => ['nullable', 'numeric', 'min:0', 'max:99999999.99'],

            // Gas purchases: shells and gas are counted and costed separately.
            // swap_catalogue_id says whose empties were sent, and so whether
            // this is a swap at all — null means cylinders were bought.
            'swap_catalogue_id' => ['nullable', 'integer', 'exists:catalogue,id'],
            'filled_quantity'   => ['nullable', 'integer', 'min:0', 'max:1000000'],
            'empty_quantity'    => ['nullable', 'integer', 'min:0', 'max:1000000'],
            'shell_unit_cost'   => ['nullable', 'numeric', 'min:0', 'max:99999999.99'],
            'gas_unit_cost'     => ['nullable', 'numeric', 'min:0', 'max:99999999.99'],

            // Plain goods: one quantity, one cost.
            'quantity'  => ['nullable', 'integer', 'min:1', 'max:1000000'],
            'unit_cost' => ['nullable', 'numeric', 'min:0', 'max:99999999.99'],
        ];
    }

    public function messages(): array
    {
        return [
            'catalogue_id.required'        => 'Choose which product was purchased.',
            'catalogue_id.exists'          => 'That product is no longer in the catalogue.',
            'purchased_at.before_or_equal' => 'A purchase cannot be dated in the future.',
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
     * Correct a purchase by appending a replacement.
     *
     * The original row is never touched. Its stock movements are reversed and
     * the replacement writes its own, so stock reflects only the corrected
     * figures while the ledger still shows both the mistake and the fix.
     *
     * @param  GasInventoryPurchase|InventoryPurchase  $original
     *
     * @throws ValidationException
     */
    public function edit(Model $original, array $data, int $recordedBy): Model
    {
        // Re-checked here rather than trusting the controller: this is the only
        // path that can rewrite stock, so the rule belongs at the write.
        if ($reason = $original->editBlockedReason()) {
            throw ValidationException::withMessages(['catalogue_id' => $reason]);
        }

        $item = Catalogue::findOrFail($data['catalogue_id']);

        // A gas purchase cannot become a plain one: the two live in different
        // tables, so the replacement would orphan its chain.
        if ($item->is_gas !== ($original instanceof GasInventoryPurchase)) {
            throw ValidationException::withMessages([
                'catalogue_id' => 'A purchase cannot be moved between cylinder and plain goods. Record a separate purchase instead.',
            ]);
        }

        return DB::transaction(function () use ($original, $item, $data, $recordedBy) {
            $this->reverseMovements($original);

            $replacement = $item->is_gas
                ? $this->recordGasPurchase($item, $data, $recordedBy)
                : $this->recordPlainPurchase($item, $data, $recordedBy);

            // Every version of a purchase shares the original's id, so the
            // chain stays flat however many times it is corrected.
            $replacement->forceFill(['canonical_id' => $original->canonicalId()])->save();

            return $replacement;
        });
    }

    /**
     * Undo a purchase's effect on stock, without deleting anything.
     *
     * Appends a negating row per movement — the append-only ledger's own
     * correction mechanism, so the original entries stay auditable.
     *
     * @param  GasInventoryPurchase|InventoryPurchase  $purchase
     */
    private function reverseMovements(Model $purchase): void
    {
        $note = 'Superseded by a correction';

        foreach ($purchase->movements as $movement) {
            $movement->reverse($note);
        }
    }

    /**
     * A cylinder purchase or swap: filled and empty shells counted separately.
     *
     * A swap sends empties away and gets filled cylinders back, moving stock
     * between the two counts without acquiring any shells. The empties need not
     * be the same product that comes back — the business can send one brand and
     * receive another.
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

        $swapItem = $this->resolveSwapItem($data, $empty);

        $purchase = GasInventoryPurchase::create([
            'catalogue_id'      => $item->id,
            'swap_catalogue_id' => $swapItem?->id,
            'supplier'          => $data['supplier'] ?? null,
            'filled_quantity'   => $filled,
            'empty_quantity'    => $empty,
            'shell_unit_cost'   => $data['shell_unit_cost'] ?? 0,
            'gas_unit_cost'     => $data['gas_unit_cost'] ?? 0,
            'transport_cost'    => $data['transport_cost'] ?? 0,
            'invoice_ref'       => $data['invoice_ref'] ?? null,
            'purchased_at'      => $data['purchased_at'],
            'recorded_by'       => $recordedBy,
        ]);

        $this->recordGasMovements($purchase, $item, $swapItem, $filled, $empty);

        return $purchase;
    }

    /**
     * The product whose empties were sent, or null when cylinders were bought.
     *
     * @throws ValidationException
     */
    private function resolveSwapItem(array $data, int $empty): ?Catalogue
    {
        if (empty($data['swap_catalogue_id'])) {
            return null;
        }

        // A swap returns gas in shells the business already owns. Sending no
        // empties away means nothing was swapped.
        if ($empty === 0) {
            throw ValidationException::withMessages([
                'empty_quantity' => 'A swap exchanges empty cylinders for filled ones — enter how many empties were sent.',
            ]);
        }

        $swapItem = Catalogue::find($data['swap_catalogue_id']);

        // Only a returnable product has shells to send back.
        if ($swapItem === null || ! $swapItem->is_returnable) {
            throw ValidationException::withMessages([
                'swap_catalogue_id' => 'Empty cylinders can only be sent for a returnable product.',
            ]);
        }

        return $swapItem;
    }

    /**
     * Put a gas purchase on the stock ledger.
     *
     * A cross-brand swap is two facts about two products — gas arrives on one,
     * empties leave the other — so it appends two rows. One combined row would
     * have to pick a single catalogue_id and would misstate both brands.
     */
    private function recordGasMovements(
        GasInventoryPurchase $purchase,
        Catalogue $item,
        ?Catalogue $swapItem,
        int $filled,
        int $empty,
    ): void {
        // Bought outright: everything that arrived is added, shells included.
        if ($swapItem === null) {
            InventoryMovement::create([
                'catalogue_id'              => $item->id,
                'gas_inventory_purchase_id' => $purchase->id,
                'reason'                    => MovementReason::Purchase,
                'filled_stock_change'       => $filled,
                'empty_stock_change'        => $empty,
                'occurred_at'               => $purchase->purchased_at,
            ]);

            return;
        }

        // Same product on both sides: one row carries both changes.
        if ($swapItem->id === $item->id) {
            InventoryMovement::create([
                'catalogue_id'              => $item->id,
                'gas_inventory_purchase_id' => $purchase->id,
                'reason'                    => MovementReason::Refill,
                'filled_stock_change'       => $filled,
                'empty_stock_change'        => -$empty,
                'occurred_at'               => $purchase->purchased_at,
            ]);

            return;
        }

        // Filled cylinders arrive on the product received.
        InventoryMovement::create([
            'catalogue_id'              => $item->id,
            'gas_inventory_purchase_id' => $purchase->id,
            'reason'                    => MovementReason::Refill,
            'filled_stock_change'       => $filled,
            'empty_stock_change'        => 0,
            'note'                      => "Swapped for {$swapItem->displayName()} empties",
            'occurred_at'               => $purchase->purchased_at,
        ]);

        // Empties leave the product sent.
        InventoryMovement::create([
            'catalogue_id'              => $swapItem->id,
            'gas_inventory_purchase_id' => $purchase->id,
            'reason'                    => MovementReason::Refill,
            'filled_stock_change'       => 0,
            'empty_stock_change'        => -$empty,
            'note'                      => "Sent for {$item->displayName()} refill",
            'occurred_at'               => $purchase->purchased_at,
        ]);
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
            ->with([
                'catalogueItem'     => fn ($query) => $query->withTrashed()->with('brand'),
                'swapCatalogueItem' => fn ($query) => $query->withTrashed()->with('brand'),
            ])
            // Superseded rows stay in the table but are not the current truth.
            ->current()
            ->latest('purchased_at')
            // Ties on purchased_at would otherwise make which rows survive the
            // limit arbitrary, and so different between requests.
            ->latest('id')
            ->limit($recentPerTable)
            ->get();

        $plain = InventoryPurchase::query()
            // withTrashed: a purchase is a historical record, so it keeps its
            // product name even after that product leaves the catalogue.
            ->with(['catalogueItem' => fn ($query) => $query->withTrashed()->with('brand')])
            // Superseded rows stay in the table but are not the current truth.
            ->current()
            ->latest('purchased_at')
            ->latest('id')
            ->limit($recentPerTable)
            ->get();

        $purchases = $gas
            ->map(fn (GasInventoryPurchase $purchase) => $this->presentGasPurchase($purchase))
            ->concat($plain->map(fn (InventoryPurchase $purchase) => $this->presentPlainPurchase($purchase)))
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
            'key'          => "{$kind}-{$purchase->id}",
            'id'           => $purchase->id,
            'kind'         => $kind,
            'display_name' => $purchase->catalogueItem->displayName(),
            'catalogue'    => $this->presentCatalogueItem($purchase->catalogueItem),
            'is_editable'  => $purchase->isEditable(),
            // Null until corrected: created_at on a replacement row is when the
            // correction was made.
            'edited_at'       => $purchase->isEdit() ? $purchase->created_at : null,
            'supplier'        => $purchase->supplier,
            'invoice_ref'     => $purchase->invoice_ref,
            'purchased_at'    => $purchase->purchased_at,
            'is_refill'       => $purchase->isSwap(),
            'swapped_for'     => $purchase->isCrossBrandSwap() ? $purchase->swapCatalogueItem->brand->name : null,
            'filled_quantity' => $purchase->filled_quantity,
            'empty_quantity'  => $purchase->empty_quantity,
            'shell_unit_cost' => (float) $purchase->shell_unit_cost,
            'unit_cost'       => (float) $purchase->gas_unit_cost,
            'transport_cost'  => (float) $purchase->transport_cost,
            'total_cost'      => $purchase->totalCost(),
        ];
    }

    /** @return array<string, mixed> */
    private function presentPlainPurchase(InventoryPurchase $purchase): array
    {
        $kind = $purchase->catalogueItem->type->value;

        return [
            'key'          => "{$kind}-{$purchase->id}",
            'id'           => $purchase->id,
            'kind'         => $kind,
            'display_name' => $purchase->catalogueItem->displayName(),
            'catalogue'    => $this->presentCatalogueItem($purchase->catalogueItem),
            'is_editable'  => $purchase->isEditable(),
            // Null until corrected: created_at on a replacement row is when the
            // correction was made.
            'edited_at'       => $purchase->isEdit() ? $purchase->created_at : null,
            'supplier'        => $purchase->supplier,
            'invoice_ref'     => $purchase->invoice_ref,
            'purchased_at'    => $purchase->purchased_at,
            'is_refill'       => false,
            'swapped_for'     => null,
            'filled_quantity' => $purchase->quantity,
            'empty_quantity'  => 0,
            'shell_unit_cost' => 0.0,
            'unit_cost'       => (float) $purchase->unit_cost,
            'transport_cost'  => (float) $purchase->transport_cost,
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

    /**
     * A purchase in the shape the add/edit form expects.
     *
     * Field names match the form's, so the same component serves both — the
     * only difference is whether the values start empty.
     *
     * @param  GasInventoryPurchase|InventoryPurchase  $purchase
     * @return array<string, mixed>
     */
    public function presentForForm(Model $purchase): array
    {
        $isGas = $purchase instanceof GasInventoryPurchase;

        return [
            'id'           => $purchase->id,
            'catalogue_id' => (string) $purchase->catalogue_id,
            'supplier'     => $purchase->supplier ?? '',
            'invoice_ref'  => $purchase->invoice_ref ?? '',
            'purchased_at' => $purchase->purchased_at->toDateString(),

            'swap_catalogue_id' => $isGas ? (string) ($purchase->swap_catalogue_id ?? '') : '',
            'filled_quantity'   => $isGas ? (string) $purchase->filled_quantity : '',
            'empty_quantity'    => $isGas ? (string) $purchase->empty_quantity : '',
            'shell_unit_cost'   => $isGas ? $this->formatCost($purchase->shell_unit_cost) : '',
            'gas_unit_cost'     => $isGas ? $this->formatCost($purchase->gas_unit_cost) : '',

            'quantity'  => $isGas ? '' : (string) $purchase->quantity,
            'unit_cost' => $isGas ? '' : $this->formatCost($purchase->unit_cost),

            'transport_cost' => $this->formatCost($purchase->transport_cost),

            'edits_used'     => $purchase->editCount(),
            'edits_allowed'  => $purchase::MAX_EDITS,
            'editable_until' => $purchase->editableUntil(),
        ];
    }

    /**
     * A cost as the form should show it.
     *
     * Zero comes back empty so the field does not read as pre-filled, and a
     * whole amount loses its ".00" tail. Trailing zeros are only ever stripped
     * from the fractional part — trimming the string outright would turn 1200
     * into 12.
     */
    private function formatCost(mixed $value): string
    {
        $amount = (float) $value;

        if ($amount === 0.0) {
            return '';
        }

        return rtrim(rtrim(number_format($amount, 2, '.', ''), '0'), '.');
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
