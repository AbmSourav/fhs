<?php

namespace App\Services;

use App\Enums\TransactionType;
use App\Models\Expense;
use App\Models\GasInventoryPurchase;
use App\Models\InventoryPurchase;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Support\BusinessCalendar;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

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
            'sales_count'    => Order::query()->happened()->count(),
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
     * A calendar month's trading, against the month before it.
     *
     * Month boundaries come from BusinessCalendar rather than Carbon directly:
     * they must fall at midnight in Dhaka, not midnight UTC, or the first six
     * hours of every month land in the wrong one.
     *
     * @return array<string, mixed>
     */
    public function monthlyFigures(): array
    {
        [$start, $end] = BusinessCalendar::monthRange();
        [$previousStart, $previousEnd] = BusinessCalendar::previousMonthRange();

        $current = $this->figuresFor($start, $end);
        $previous = $this->figuresFor($previousStart, $previousEnd);

        return [
            'month_label'          => BusinessCalendar::monthLabel(),
            'previous_month_label' => BusinessCalendar::monthLabel(
                BusinessCalendar::now()->startOfMonth()->subMonthNoOverflow(),
            ),
            'revenue'       => $this->withDelta($current['revenue'], $previous['revenue']),
            'sales_count'   => $this->withDelta($current['sales_count'], $previous['sales_count']),
            'gross_profit'  => $this->withDelta($current['gross_profit'], $previous['gross_profit']),
            'average_order' => $this->withDelta($current['average_order'], $previous['average_order']),
            'collected'     => $this->withDelta($current['collected'], $previous['collected']),
        ];
    }

    /**
     * Series for the dashboard's charts.
     *
     * Rows are fetched once across the whole span and bucketed in PHP against
     * ranges from BusinessCalendar. Grouping in SQL would need a timezone-aware
     * date function — Postgres `AT TIME ZONE`, which SQLite has no equivalent
     * for — leaving the bucketing untested where it is most likely to be wrong.
     *
     * @return array<string, mixed>
     */
    public function trends(int $months = 12): array
    {
        $monthly = BusinessCalendar::recentMonths($months);
        $daily = BusinessCalendar::daysInMonth();

        $span = [
            'start' => $monthly[0]['start'],
            'end'   => $monthly[count($monthly) - 1]['end'],
        ];

        $revenue = $this->revenueRows($span['start'], $span['end']);
        $payments = $this->paymentRows($span['start'], $span['end']);
        $mix = $this->transactionRows($span['start'], $span['end']);

        return [
            'monthly' => $this->buildMonthlySeries($monthly, $revenue, $payments, $mix),
            'daily'   => $this->buildDailySeries($daily, $revenue),
        ];
    }

    /**
     * Revenue and cash by month, plus the swap-versus-outright split.
     *
     * Every bucket is present even when empty: a quiet month is a real data
     * point, and dropping it would silently shorten the axis.
     *
     * @return array<int, array<string, mixed>>
     */
    private function buildMonthlySeries(array $months, array $revenue, array $payments, array $mix): array
    {
        return array_map(fn (array $month) => [
            'label'     => $month['label'],
            'revenue'   => $this->sumWithin($revenue, $month['start'], $month['end'], 'amount'),
            'collected' => $this->sumWithin($payments, $month['start'], $month['end'], 'amount'),
            'swap'      => $this->sumWithin($mix, $month['start'], $month['end'], 'swap'),
            'outright'  => $this->sumWithin($mix, $month['start'], $month['end'], 'outright'),
        ], $months);
    }

    /**
     * Revenue for each day of the month in progress.
     *
     * @return array<int, array<string, mixed>>
     */
    private function buildDailySeries(array $days, array $revenue): array
    {
        return array_map(fn (array $day) => [
            'label'   => $day['label'],
            'revenue' => $this->sumWithin($revenue, $day['start'], $day['end'], 'amount'),
        ], $days);
    }

    /**
     * Total one column of the rows falling inside a bucket.
     *
     * Half-open, matching every other range in the application: a row landing
     * exactly on a boundary belongs to the later bucket only.
     */
    private function sumWithin(array $rows, CarbonImmutable $start, CarbonImmutable $end, string $column): float
    {
        $total = 0.0;

        foreach ($rows as $row) {
            if ($row['at'] >= $start && $row['at'] < $end) {
                $total += (float) $row[$column];
            }
        }

        return round($total, 2);
    }

    /**
     * Sale lines with when they happened, already totalled per order.
     *
     * @return array<int, array{at: CarbonImmutable, amount: float}>
     */
    private function revenueRows(CarbonImmutable $start, CarbonImmutable $end): array
    {
        return Order::query()
            ->happened()
            ->where('occurred_at', '>=', $start)
            ->where('occurred_at', '<', $end)
            ->withSum('items as revenue', 'line_total')
            ->get(['id', 'occurred_at'])
            ->map(fn (Order $order) => [
                'at'     => CarbonImmutable::parse($order->occurred_at),
                'amount' => (float) $order->revenue,
            ])
            ->all();
    }

    /**
     * Payments with when the money arrived.
     *
     * @return array<int, array{at: CarbonImmutable, amount: float}>
     */
    private function paymentRows(CarbonImmutable $start, CarbonImmutable $end): array
    {
        return Payment::query()
            ->whereIn('order_id', $this->soldOrderIds())
            ->where('paid_at', '>=', $start)
            ->where('paid_at', '<', $end)
            ->get(['amount', 'paid_at'])
            ->map(fn (Payment $payment) => [
                'at'     => CarbonImmutable::parse($payment->paid_at),
                'amount' => (float) $payment->amount,
            ])
            ->all();
    }

    /**
     * Units sold, split by whether the cylinder came back.
     *
     * A rising outright share means shells are leaving the business rather than
     * circulating, which is the shape of this trend worth watching.
     *
     * @return array<int, array{at: CarbonImmutable, swap: int, outright: int}>
     */
    private function transactionRows(CarbonImmutable $start, CarbonImmutable $end): array
    {
        return OrderItem::query()
            ->whereIn('order_id', $this->soldOrderIds($start, $end))
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->get(['order_items.transaction_type', 'order_items.quantity', 'orders.occurred_at'])
            ->map(fn (OrderItem $item) => [
                'at' => CarbonImmutable::parse($item->occurred_at),
                // A swap returns the shell; the other kinds do not.
                'swap'     => $item->transaction_type === TransactionType::Swap ? $item->quantity : 0,
                'outright' => $item->transaction_type === TransactionType::Swap ? 0 : $item->quantity,
            ])
            ->all();
    }

    /**
     * One month's raw figures, before any comparison.
     *
     * Written once and parameterised by range, so the current month and the one
     * before it cannot drift apart.
     *
     * @return array<string, float|int>
     */
    private function figuresFor(CarbonImmutable $start, CarbonImmutable $end): array
    {
        $revenue = $this->revenue($start, $end);
        $salesCount = $this->salesCount($start, $end);

        return [
            'revenue'      => $revenue,
            'sales_count'  => $salesCount,
            'gross_profit' => round($revenue - $this->costOfGoodsSold($start, $end), 2),
            // Whether a change in revenue came from more sales or bigger ones.
            'average_order' => $salesCount > 0 ? round($revenue / $salesCount, 2) : 0.0,
            'collected'     => $this->collected($start, $end),
        ];
    }

    /**
     * How this month compares with the last.
     *
     * A percentage against a zero base is undefined rather than infinite, so it
     * is reported as null and the direction says what happened instead.
     *
     * @return array<string, mixed>
     */
    private function withDelta(float|int $current, float|int $previous): array
    {
        $delta = round($current - $previous, 2);

        if ((float) $previous === 0.0) {
            return [
                'current'   => $current,
                'previous'  => $previous,
                'delta'     => $delta,
                'percent'   => null,
                'direction' => $current > 0 ? 'new' : 'flat',
            ];
        }

        return [
            'current'  => $current,
            'previous' => $previous,
            'delta'    => $delta,
            'percent'  => round(($delta / abs($previous)) * 100, 1),
            // Taken from the sign of the delta, never the percentage: with a
            // negative previous value, dividing flips the sign and a genuine
            // improvement would read as a decline.
            'direction' => match (true) {
                $delta > 0 => 'up',
                $delta < 0 => 'down',
                default    => 'flat',
            },
        ];
    }

    /**
     * Everything billed to customers, optionally within a range.
     *
     * Read from order_items rather than orders.total_amount: the latter is a
     * denormalised convenience, and revenue reporting uses the lines that make
     * it up.
     */
    private function revenue(?CarbonImmutable $start = null, ?CarbonImmutable $end = null): float
    {
        return (float) OrderItem::query()
            ->whereIn('order_id', $this->soldOrderIds($start, $end))
            // SUM over no rows is NULL, and a business with no sales has
            // revenue of zero, not nothing.
            ->selectRaw('COALESCE(SUM(line_total), 0) as revenue')
            ->value('revenue');
    }

    /**
     * Sales in the range.
     *
     * Counted on orders rather than as a DISTINCT over their lines, so a sale
     * recorded without items would still be counted.
     */
    private function salesCount(CarbonImmutable $start, CarbonImmutable $end): int
    {
        return Order::query()
            ->happened()
            ->where('occurred_at', '>=', $start)
            ->where('occurred_at', '<', $end)
            ->count();
    }

    /**
     * Money actually received in the range.
     *
     * Keyed on when payment arrived, not when the sale happened — a sale on the
     * 28th settled on the 3rd is this month's cash and last month's revenue.
     */
    private function collected(CarbonImmutable $start, CarbonImmutable $end): float
    {
        return round((float) Payment::query()
            // Scoped to orders that happened, but not to the month: a payment
            // now against an older sale is still cash collected now.
            ->whereIn('order_id', $this->soldOrderIds())
            ->where('paid_at', '>=', $start)
            ->where('paid_at', '<', $end)
            ->sum('amount'), 2);
    }

    /**
     * What the goods sold actually cost.
     *
     * `unit_cost` is the weighted average frozen at the moment of sale, and it
     * already reflects what each transaction consumed: a swap carries gas only,
     * while an outright cylinder sale carries the shell too, because that shell
     * left the business for good.
     */
    private function costOfGoodsSold(?CarbonImmutable $start = null, ?CarbonImmutable $end = null): float
    {
        return round((float) OrderItem::query()
            ->whereIn('order_id', $this->soldOrderIds($start, $end))
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
     * Kept as a Builder rather than a fetched list: the set can be unbounded,
     * and every caller only ever uses it inside a whereIn.
     *
     * The range is half-open — `>= $start` and `< $end`, never whereBetween,
     * which is inclusive at both ends and would count a sale landing exactly on
     * a month boundary twice.
     */
    private function soldOrderIds(?CarbonImmutable $start = null, ?CarbonImmutable $end = null): Builder
    {
        return Order::query()
            ->happened()
            ->when($start, fn (Builder $query) => $query->where('occurred_at', '>=', $start))
            ->when($end, fn (Builder $query) => $query->where('occurred_at', '<', $end))
            ->select('id');
    }
}
