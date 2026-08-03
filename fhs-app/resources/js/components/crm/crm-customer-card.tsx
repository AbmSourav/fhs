import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { BUSINESS_TIME_ZONE } from '@/lib/datetime';
import { type CrmCustomer } from '@/types/customer';
import { Link, router } from '@inertiajs/react';
import { Clock, History, MapPin, Phone, Repeat } from 'lucide-react';
import { useState } from 'react';

// narrowSymbol gives the ৳ sign; the default for BDT is the "BDT" code.
const currency = new Intl.NumberFormat('en-BD', {
    style: 'currency',
    currency: 'BDT',
    currencyDisplay: 'narrowSymbol',
    maximumFractionDigits: 0,
});

// en-GB puts the day before the month, giving "29 Jul 26". Fixed to business
// time: stored timestamps are UTC, so the browser's own zone could show a sale
// falling on the wrong day.
const date = new Intl.DateTimeFormat('en-GB', {
    day: 'numeric',
    month: 'short',
    year: '2-digit',
    timeZone: BUSINESS_TIME_ZONE,
});

/** "33 days ago", or "Today" when they bought this morning. */
function sinceLabel(days: number | null): string {
    if (days === null) {
        return '—';
    }

    if (days === 0) {
        return 'Today';
    }

    return `${days} ${days === 1 ? 'day' : 'days'} ago`;
}

/**
 * A customer on a call list.
 *
 * Separate from the customer book's card: this one leads with how long it has
 * been and offers the call, which is the only reason the page exists.
 */
export default function CrmCustomerCard({ customer }: { customer: CrmCustomer }) {
    const [calling, setCalling] = useState(false);

    const call = () => {
        setCalling(true);

        // The dialler is opened first and synchronously. Doing it after the
        // POST resolves would put it outside the click handler, and browsers
        // block navigation that is not tied to a user gesture.
        if (customer.mobile_number) {
            window.location.href = `tel:${customer.mobile_number}`;
        }

        // Logs the call and redirects to the write-up form. The current filters
        // ride along so the form can send staff back to the same list.
        router.post(
            `/crm/${customer.id}/call`,
            { filters: window.location.search },
            { onFinish: () => setCalling(false) },
        );
    };

    return (
        <li className="rounded-lg border-2 p-3">
            <div className="flex items-start justify-between gap-3">
                <div className="min-w-0">
                    <p className="truncate font-medium">{customer.name}</p>

                    {customer.mobile_number && (
                        <p className="text-muted-foreground mt-0.5 truncate text-sm font-medium">{customer.mobile_number}</p>
                    )}

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

                    {customer.has_lapsed && (
                        <Badge variant="destructive" className="shrink-0 gap-1">
                            <Clock className="size-3" />
                            Lapsed
                        </Badge>
                    )}
                </div>
            </div>

            <dl className="mt-3 flex flex-wrap gap-x-6 gap-y-3 border-t pt-3 text-sm">
                {/* The reason they are on this list, so it leads. */}
                <div>
                    <dt className="text-muted-foreground text-xs">Last bought</dt>
                    <dd className="mt-0.5 font-medium">{sinceLabel(customer.days_since_order)}</dd>
                </div>

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

                {/* Only once somebody has called, so a fresh list is not full
                    of empty columns. */}
                {customer.last_called_at && (
                    <div>
                        <dt className="text-muted-foreground text-xs">Last called</dt>
                        <dd className="mt-0.5 font-medium">{date.format(new Date(customer.last_called_at))}</dd>
                    </div>
                )}
            </dl>

            <div className="mt-3 flex items-center justify-between gap-2 border-t pt-3">
                <Button variant="outline" size="sm" className="h-7 gap-1 p-2 text-xs" asChild>
                    <Link href={`/customers/${customer.id}/history`}>
                        <History className="size-3" />
                        History
                    </Link>
                </Button>

                {/* Nothing to dial without a number, and no call to log. */}
                <Button size="sm" className="h-7 gap-1 px-3" onClick={call} disabled={calling || !customer.mobile_number}>
                    <Phone className="size-3" />
                    Call
                </Button>
            </div>
        </li>
    );
}
