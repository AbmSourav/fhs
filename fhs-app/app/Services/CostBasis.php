<?php

namespace App\Services;

use App\Enums\TransactionType;
use App\Models\Catalogue;
use App\Models\GasInventoryPurchase;
use App\Models\InventoryPurchase;
use Illuminate\Support\Collection;

/**
 * What a unit of a product costs, averaged over everything bought so far.
 *
 * Gas and shell are averaged separately because they are sold separately: a
 * swap consumes gas only, so charging it the shell cost too would overstate the
 * cost of the most common transaction by the whole price of a cylinder.
 *
 * Shared rather than living on OrderService, so a purchase can be shown against
 * the same figure a sale is costed at. Two implementations would drift, and the
 * one number staff compare a sale price against would stop being the one the
 * books use.
 */
class CostBasis
{
    /**
     * Averages for one product, keyed 'gas', 'shell' and 'plain'.
     *
     * @return array<string, float>
     */
    public function forItem(Catalogue $item): array
    {
        return $this->forItems(collect([$item]))[$item->id];
    }

    /**
     * The cost a sale of this kind draws on.
     *
     * Mirrors what OrderService freezes onto order_items.unit_cost, so a
     * purchase card and a sale line describe the same money.
     */
    public function forSale(Catalogue $item, TransactionType $type): float
    {
        $average = $this->forItem($item);

        if (! $item->is_gas) {
            return $average['plain'];
        }

        return round(
            ($type->includesGas() ? $average['gas'] : 0.0)
                + ($type->includesShell() ? $average['shell'] : 0.0),
            2,
        );
    }

    /**
     * Averages for many products at once.
     *
     * Three queries whatever the number of products, rather than three per
     * product — a list of purchases would otherwise be an N+1.
     *
     * @param  Collection<int, Catalogue>  $items
     * @return array<int, array<string, float>>
     */
    public function forItems(Collection $items): array
    {
        $ids = $items->pluck('id')->unique()->all();

        if ($ids === []) {
            return [];
        }

        $gas = $this->gasAverages($ids);
        $shell = $this->shellAverages($ids);
        $plain = $this->plainAverages($ids);

        $averages = [];

        foreach ($ids as $id) {
            $averages[$id] = [
                'gas'   => $gas[$id] ?? 0.0,
                'shell' => $shell[$id] ?? 0.0,
                'plain' => $plain[$id] ?? 0.0,
            ];
        }

        return $averages;
    }

    /**
     * @param  array<int, int>  $ids
     * @return array<int, float>
     */
    private function gasAverages(array $ids): array
    {
        return GasInventoryPurchase::query()
            // Only the newest version of each purchase: corrections append a
            // replacement row, so counting every version would average in
            // figures the business already fixed.
            ->current()
            ->whereIn('catalogue_id', $ids)
            // Only filled cylinders carry gas, so empties must not dilute it.
            ->where('filled_quantity', '>', 0)
            ->groupBy('catalogue_id')
            ->selectRaw('catalogue_id, SUM(gas_unit_cost * filled_quantity) as cost, SUM(filled_quantity) as qty')
            ->get()
            ->mapWithKeys(fn ($row) => [$row->catalogue_id => $this->divide((float) $row->cost, (int) $row->qty)])
            ->all();
    }

    /**
     * @param  array<int, int>  $ids
     * @return array<int, float>
     */
    private function shellAverages(array $ids): array
    {
        return GasInventoryPurchase::query()
            ->current()
            ->whereIn('catalogue_id', $ids)
            // Swaps acquire no shells, so they are excluded — including them
            // would divide by cylinders that were never bought.
            ->newStock()
            ->groupBy('catalogue_id')
            ->selectRaw('catalogue_id, SUM(shell_unit_cost * (filled_quantity + empty_quantity)) as cost, SUM(filled_quantity + empty_quantity) as qty')
            ->get()
            ->mapWithKeys(fn ($row) => [$row->catalogue_id => $this->divide((float) $row->cost, (int) $row->qty)])
            ->all();
    }

    /**
     * @param  array<int, int>  $ids
     * @return array<int, float>
     */
    private function plainAverages(array $ids): array
    {
        return InventoryPurchase::query()
            ->current()
            ->whereIn('catalogue_id', $ids)
            ->groupBy('catalogue_id')
            ->selectRaw('catalogue_id, SUM(unit_cost * quantity) as cost, SUM(quantity) as qty')
            ->get()
            ->mapWithKeys(fn ($row) => [$row->catalogue_id => $this->divide((float) $row->cost, (int) $row->qty)])
            ->all();
    }

    /** Nothing purchased yet means no cost basis, not a division by zero. */
    private function divide(float $cost, int $quantity): float
    {
        return $quantity > 0 ? round($cost / $quantity, 2) : 0.0;
    }
}
