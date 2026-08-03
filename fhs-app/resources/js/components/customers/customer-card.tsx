import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { BUSINESS_TIME_ZONE } from '@/lib/datetime';
import { type Customer } from '@/types/customer';
import { Link } from '@inertiajs/react';
import { Clock, History, MapPin, Pencil, Repeat } from 'lucide-react';

// narrowSymbol gives the ৳ sign; the default for BDT is the "BDT" code.
const currency = new Intl.NumberFormat('en-BD', {
    style: 'currency',
    currency: 'BDT',
    currencyDisplay: 'narrowSymbol',
    maximumFractionDigits: 0,
});

// en-GB puts the day before the month, giving "29 Jul 2026". Fixed to business
// time: stored timestamps are UTC, so the browser's own zone could show a sale
// falling on the wrong day.
const date = new Intl.DateTimeFormat('en-GB', {
    day: 'numeric',
    month: 'short',
    year: '2-digit',
    timeZone: BUSINESS_TIME_ZONE,
});

/** A single customer with their trading history. */
export default function CustomerCard({ customer }: { customer: Customer }) {
    return (
        <li className="rounded-lg border-2 p-3">
            <div className="flex items-start justify-between gap-3">
                <div className="min-w-0">
                    <p className="truncate font-medium">{customer.name}</p>

                    <div className="flex items-center">
                        {customer.mobile_number &&
                            <p className="text-muted-foreground mt-0.5 truncate text-sm font-medium mr-3">{customer.mobile_number}</p>
                        }
                    </div>

                    {customer.address && (
                        <p className="text-muted-foreground mt-1 flex items-start gap-1 text-xs">
                            <MapPin className="mt-0.5 size-3 shrink-0" />
                            <span className="line-clamp-2">{customer.address}</span>
                        </p>
                    )}
                </div>

                <div className="flex shrink-0 flex-col items-end gap-1">
                    <Badge variant="success" className="shrink-0 gap-1">
                        <Repeat className="size-3" />
                        {customer.order_count} orders
                    </Badge>

                    {/* Overdue for a repeat purchase. The order count above
                        says whether this was a regular or a one-off. */}
                    {customer.has_lapsed && (
                        <Badge variant="destructive" className="shrink-0 gap-1">
                            <Clock className="size-3" />
                            Lapsed
                        </Badge>
                    )}

                    <Button variant="secondary" size="sm" className="rounded-full h-7 gap-1 p-2 mr-1" asChild>
                        <Link href={`/customers/edit/${customer.id}`}>
                            <Pencil className="size-3" />
                        </Link>
                    </Button>
                </div>
            </div>

            <div className="mt-2 flex items-end justify-between gap-3 border-t pt-3">
                <dl className="flex flex-wrap gap-x-6 gap-y-3 text-sm">
                    <div>
                        <dt className="text-muted-foreground text-xs">Spent</dt>
                        <dd className="mt-0.5 font-medium tabular-nums">{currency.format(customer.total_spent)}</dd>
                    </div>

                    {customer.due_amount > 0 && (
                        <div>
                            <dt className="text-muted-foreground text-xs">Due</dt>
                            <dd className="text-destructive mt-0.5 font-medium tabular-nums">{currency.format(customer.due_amount)}</dd>
                        </div>
                    )}

                    <div>
                        <dt className="text-muted-foreground text-xs">Last order</dt>
                        <dd className="mt-0.5 font-medium">
                            {customer.last_ordered_at ? date.format(new Date(customer.last_ordered_at)) : '—'}
                        </dd>
                    </div>

                </dl>

                <div className="flex shrink-0 items-center gap-2">
                    <Button variant="outline" size="sm" className="h-7 gap-1 p-2 text-xs" asChild>
                        <Link href={`/customers/${customer.id}/history`}>
                            <History className="size-3" />
                            History
                        </Link>
                    </Button>
                </div>
            </div>
        </li>
    );
}
