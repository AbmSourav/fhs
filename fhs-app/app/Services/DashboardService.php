<?php

namespace App\Services;

use App\Enums\TransactionType;
use App\Models\Expense;
use App\Models\GasInventoryPurchase;
use App\Models\InventoryPurchase;
use App\Models\Order;
use App\Models\OrderItem;

/**
 * Summarising the business for the dashboard.
 *
 * Every figure is derived from the records themselves rather than stored, so a
 * corrected order or a deleted expense changes the dashboard immediately.
 */
class DashboardService
{
    /**
     * Life-of-the-business totals.
     *
     * Cost is matched to what actually sold, not to what was bought. Buying
     * stock converts money into goods rather than spending it — the cost lands
     * when the goods leave, which is what `order_items.unit_cost` records.
     * Totalling purchases instead would report a loss after every delivery and
     * a windfall in any month that ran the shelves down.
     *
     * @return array<string, float>
     */
    public function allTimePosition(): array
    {
        $revenue = $this->revenue();
        $cogs = $this->costOfGoodsSold();
        $otherExpenses = $this->otherExpenses();
        $stockValue = $this->stockValue();
        $shellValue = $this->shellValue();

        return [
            'revenue'        => $revenue,
            'cogs'           => $cogs,
            'other_expenses' => $otherExpenses,
            'gross_profit'   => round($revenue - $cogs, 2),
            'net_profit'     => round($revenue - $cogs - $otherExpenses, 2),
            // What the business still holds rather than has spent.
            'stock_value'    => $stockValue,
            'shell_value'    => $shellValue,
            'current_assets' => round($stockValue + $shellValue, 2),
        ];
    }

    /**
     * Everything ever billed to customers.
     *
     * Read from order_items rather than orders.total_amount: the latter is a
     * denormalised convenience, and revenue reporting uses the lines that make
     * it up.
     */
    private function revenue(): float
    {
        return (float) OrderItem::query()
            ->whereIn('order_id', $this->soldOrderIds())
            // SUM over no rows is NULL, and a business with no sales has
            // revenue of zero, not nothing.
            ->selectRaw('COALESCE(SUM(line_total), 0) as revenue')
            ->value('revenue');
    }

    /**
     * What the goods sold actually cost.
     *
     * `unit_cost` is the weighted average frozen at the moment of sale, and it
     * already reflects what each transaction consumed: a swap carries gas only,
     * while an outright cylinder sale carries the shell too, because that shell
     * left the business for good.
     */
    private function costOfGoodsSold(): float
    {
        return round((float) OrderItem::query()
            ->whereIn('order_id', $this->soldOrderIds())
            ->selectRaw('COALESCE(SUM(unit_cost * quantity), 0) as cost')
            ->value('cost'), 2);
    }

    /**
     * Gas and goods bought but not yet sold, at what they cost.
     *
     * Valued at the average purchase cost rather than the sale price: unsold
     * stock is money spent, not money earned.
     */
    private function stockValue(): float
    {
        $row = GasInventoryPurchase::query()
            ->current()
            // Gas rides in filled cylinders, so only those carry any.
            ->selectRaw('COALESCE(SUM(gas_unit_cost * filled_quantity), 0) as cost')
            ->selectRaw('COALESCE(SUM(filled_quantity), 0) as qty')
            ->first();

        $gasBought = (float) $row->cost;
        $gasQuantity = (int) $row->qty;

        // Valued per unit for the same reason shells are: unit_cost blends gas
        // and shell on an outright sale, so it cannot be split afterwards.
        $gasSold = $gasQuantity > 0
            ? ($gasBought / $gasQuantity) * $this->gasUnitsSold()
            : 0.0;

        $plainBought = (float) InventoryPurchase::query()
            ->current()
            ->selectRaw('COALESCE(SUM(unit_cost * quantity + transport_cost), 0) as cost')
            ->value('cost');

        // Plain goods have no shell, so their frozen cost is unambiguous.
        $plainSold = $this->plainCostConsumed();

        // Never negative: selling more than was recorded as bought means the
        // purchase records are incomplete, not that stock is worth less than
        // nothing.
        return round(max(($gasBought - $gasSold) + ($plainBought - $plainSold), 0), 2);
    }

    /**
     * What the cylinders the business owns cost to buy.
     *
     * A shell is a durable asset, not a consumable: a swap returns the empty
     * and the steel stays owned, so its cost must not be treated as spending.
     * Only a cylinder sold outright leaves, and that cost is in COGS.
     */
    private function shellValue(): float
    {
        // Swaps acquire no shells — they return gas in cylinders already owned,
        // so counting them would inflate the total.
        $row = GasInventoryPurchase::query()
            ->current()
            ->newStock()
            ->selectRaw('COALESCE(SUM(shell_unit_cost * (filled_quantity + empty_quantity)), 0) as cost')
            ->selectRaw('COALESCE(SUM(filled_quantity + empty_quantity), 0) as qty')
            ->first();

        $bought = (float) $row->cost;
        $quantity = (int) $row->qty;

        if ($quantity === 0) {
            return 0.0;
        }

        // Valued per shell rather than from order_items.unit_cost: that column
        // blends gas and shell into one figure on an outright sale, so
        // subtracting it whole would take the gas out of shell value as well.
        $averageCost = $bought / $quantity;

        return round(max($bought - ($averageCost * $this->shellsSold()), 0), 2);
    }

    /**
     * How many units of gas have been sold.
     *
     * Everything except a bare shell sale carries gas. A count, for the same
     * reason shells are counted: the frozen cost cannot be split.
     */
    private function gasUnitsSold(): int
    {
        return (int) OrderItem::query()
            ->whereIn('order_id', $this->soldOrderIds())
            ->whereIn('transaction_type', [
                TransactionType::Swap->value,
                TransactionType::BuyWithGas->value,
            ])
            ->join('catalogue', 'catalogue.id', '=', 'order_items.catalogue_id')
            ->where('catalogue.is_gas', true)
            ->selectRaw('COALESCE(SUM(order_items.quantity), 0) as sold')
            ->value('sold');
    }

    /** Non-gas goods sold, to take out of stock on hand. */
    private function plainCostConsumed(): float
    {
        return (float) OrderItem::query()
            ->whereIn('order_id', $this->soldOrderIds())
            ->join('catalogue', 'catalogue.id', '=', 'order_items.catalogue_id')
            ->where('catalogue.is_gas', false)
            ->selectRaw('COALESCE(SUM(order_items.unit_cost * order_items.quantity), 0) as cost')
            ->value('cost');
    }

    /**
     * How many cylinders have been sold outright.
     *
     * A count rather than a cost: only the two outright transactions hand a
     * shell over, and a swap keeps it. Counted because `unit_cost` blends gas
     * and shell, so the shell's share of it cannot be recovered afterwards.
     */
    private function shellsSold(): int
    {
        return (int) OrderItem::query()
            ->whereIn('order_id', $this->soldOrderIds())
            ->whereIn('transaction_type', [
                TransactionType::BuyWithGas->value,
                TransactionType::BuyEmpty->value,
            ])
            ->join('catalogue', 'catalogue.id', '=', 'order_items.catalogue_id')
            ->where('catalogue.is_gas', true)
            ->selectRaw('COALESCE(SUM(order_items.quantity), 0) as sold')
            ->value('sold');
    }

    /**
     * Non-stock spending — fuel, wages, rent.
     *
     * Soft-deleted expenses are excluded by the model's global scope: a deleted
     * expense stops counting toward what was spent.
     */
    private function otherExpenses(): float
    {
        return round((float) Expense::query()->sum('amount'), 2);
    }

    /**
     * Orders that actually happened, as a subquery.
     *
     * Kept as a Builder rather than a fetched list: the set is unbounded, and
     * every caller only ever uses it inside a whereIn.
     */
    private function soldOrderIds()
    {
        return Order::query()->happened()->select('id');
    }
}
