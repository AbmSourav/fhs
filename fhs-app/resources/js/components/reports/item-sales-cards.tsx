import { type ReportItem } from '@/types/dashboard';

// narrowSymbol gives the ৳ sign; the default for BDT is the "BDT" code.
const currency = new Intl.NumberFormat('en-BD', {
    style: 'currency',
    currency: 'BDT',
    currencyDisplay: 'narrowSymbol',
    maximumFractionDigits: 0,
});

const count = new Intl.NumberFormat('en-BD');

/**
 * What sold in the month, item by item.
 *
 * Swap and outright are shown separately because the distinction matters more
 * than the quantity does: a month heavy on outright sales is draining cylinders
 * out of circulation, which the money figures alone do not show.
 */
export default function ItemSalesCards({ items }: { items: ReportItem[] }) {
    const total = items.reduce(
        (running, item) => ({
            quantity: running.quantity + item.quantity,
            swapped: running.swapped + item.swapped,
            outright: running.outright + item.outright,
            revenue: running.revenue + item.revenue,
        }),
        { quantity: 0, swapped: 0, outright: 0, revenue: 0 },
    );

    return (
        <div>
            <ul className="grid gap-2 sm:grid-cols-2">
                {items.map((item) => (
                    <li key={item.name} data-print="block" className="rounded-lg border p-3">
                        <div className="flex items-start justify-between gap-3">
                            <p className="min-w-0 truncate text-sm font-medium">{item.name}</p>
                            <p className="shrink-0 text-sm font-semibold tabular-nums">{currency.format(item.revenue)}</p>
                        </div>

                        <dl className="mt-3 flex flex-wrap gap-x-6 gap-y-2 border-t pt-2">
                            <div>
                                <dt className="text-muted-foreground text-[10px]">Sold</dt>
                                <dd className="mt-0.5 text-sm font-medium tabular-nums">{count.format(item.quantity)}</dd>
                            </div>

                            <div>
                                <dt className="text-muted-foreground text-[10px]">Swap</dt>
                                <dd className="mt-0.5 text-sm font-medium tabular-nums">{count.format(item.swapped)}</dd>
                            </div>

                            <div>
                                <dt className="text-muted-foreground text-[10px]">Outright</dt>
                                {/* Every outright sale is a shell that left the
                                    business, so it is worth noticing when the
                                    number is not zero. */}
                                <dd
                                    className={
                                        'mt-0.5 text-sm font-medium tabular-nums ' +
                                        (item.outright > 0 ? 'text-amber-600 dark:text-amber-500' : '')
                                    }
                                >
                                    {count.format(item.outright)}
                                </dd>
                            </div>
                        </dl>
                    </li>
                ))}
            </ul>

            {/* Only worth stating once there is more than one card to add up. */}
            {items.length > 1 && (
                <dl data-print="block" className="mt-2 flex flex-wrap items-baseline gap-x-6 gap-y-1 rounded-lg border-1 p-3 bg-gray-100 dark:bg-gray-900">
                    <div>
                        <dt className="text-muted-foreground text-[10px]">Total</dt>
                        <dd className="mt-0.5 font-semibold tabular-nums">{count.format(total.quantity)}</dd>
                    </div>

                    <div>
                        <dt className="text-muted-foreground text-[10px]">Swap</dt>
                        <dd className="mt-0.5 font-semibold tabular-nums">{count.format(total.swapped)}</dd>
                    </div>

                    <div>
                        <dt className="text-muted-foreground text-[10px]">Outright</dt>
                        <dd className="mt-0.5 font-semibold tabular-nums">{count.format(total.outright)}</dd>
                    </div>

                    {/* Ties back to the revenue above: the same money, divided
                        by what produced it. */}
                    <div className="ml-auto text-right">
                        <dt className="text-muted-foreground text-[10px]">Revenue</dt>
                        <dd className="mt-0.5 font-semibold tabular-nums">{currency.format(total.revenue)}</dd>
                    </div>
                </dl>
            )}
        </div>
    );
}
