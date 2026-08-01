import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { formatDate, formatDateTime } from '@/lib/datetime';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { type CustomerProfile } from '@/types/customer';
import { Head, Link } from '@inertiajs/react';
import { ArrowLeft, Clock, MapPin, Package, Pencil } from 'lucide-react';

// narrowSymbol gives the ৳ sign; the default for BDT is the "BDT" code.
const currency = new Intl.NumberFormat('en-BD', {
    style: 'currency',
    currency: 'BDT',
    currencyDisplay: 'narrowSymbol',
    maximumFractionDigits: 0,
});

// Shown in business time: stored timestamps are UTC, so leaving it to the
// browser would render the same sale differently in another timezone.
const date = formatDate;

// Timeline entries carry the time too, so one day's orders read in sequence.
const dateTime = formatDateTime;

const paymentBadge = {
    paid: { label: 'Paid', variant: 'success' },
    partial: { label: 'Partial', variant: 'warning' },
    due: { label: 'Due', variant: 'destructive' },
} as const;

/** How long ago, in whole days. */
function daysSince(iso: string): number {
    return Math.floor((Date.now() - new Date(iso).getTime()) / 86_400_000);
}

export default function CustomerHistory({ customer }: { customer: CustomerProfile }) {
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Customers', href: '/customers' },
        { title: customer.name, href: '#' },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`${customer.name} — history`} />

            <div className="px-4 py-6">
                <Button variant="outline" asChild className="mb-8 shrink-0 self-start">
                    <Link href="/customers">
                        <ArrowLeft className="mr-1 size-4" />
                        Back to Customers
                    </Link>
                </Button>

                <div className="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                    <div className="min-w-0">
                        <div className="flex flex-wrap items-center gap-2">
                            <h1 className="text-xl font-semibold">{customer.name}</h1>

                            {/* Overdue for a repeat purchase — the order count
                                beside it says whether they were a regular. */}
                            {customer.has_lapsed && (
                                <Badge variant="destructive" className="gap-1">
                                    <Clock className="size-3" />
                                    Lapsed
                                </Badge>
                            )}
                        </div>

                        {customer.mobile_number && <p className="text-muted-foreground mt-1 text-sm">{customer.mobile_number}</p>}

                        {customer.address && (
                            <p className="text-muted-foreground mt-1 flex items-start gap-1 text-sm">
                                <MapPin className="mt-0.5 size-3 shrink-0" />
                                {customer.address}
                            </p>
                        )}
                    </div>

                    <Button variant="outline" size="sm" className="h-8 shrink-0 gap-1 self-start" asChild>
                        <Link href={`/customers/edit/${customer.id}`}>
                            <Pencil className="size-3" />
                            Edit
                        </Link>
                    </Button>
                </div>

                {customer.has_lapsed && customer.last_ordered_at && (
                    <p className="text-muted-foreground mt-3 text-sm">
                        No order in {daysSince(customer.last_ordered_at)} days — a customer is counted as lapsed after{' '}
                        {customer.lapsed_after_days}.
                    </p>
                )}

                <dl className="mt-6 flex flex-wrap gap-x-8 gap-y-4 rounded-lg border p-4 text-sm">
                    <div>
                        <dt className="text-muted-foreground text-xs">Orders</dt>
                        <dd className="mt-0.5 font-medium tabular-nums">{customer.order_count}</dd>
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
                    <div>
                        <dt className="text-muted-foreground text-xs">Last order</dt>
                        <dd className="mt-0.5 font-medium">
                            {customer.last_ordered_at ? date.format(new Date(customer.last_ordered_at)) : '—'}
                        </dd>
                    </div>
                </dl>

                <h2 className="mt-8 font-medium">History</h2>

                {customer.timeline.length === 0 ? (
                    <div className="mt-4 flex flex-col items-center justify-center rounded-lg border border-dashed px-6 py-12 text-center">
                        <Package className="text-muted-foreground size-8" />
                        <p className="text-muted-foreground mt-3 text-sm">This customer has not bought anything yet.</p>
                    </div>
                ) : (
                    /* The border runs down the left as the timeline spine; each
                       entry hangs its own marker on it. */
                    <ol className="border-border border-green-200 mt-4 space-y-6 border-l pl-6">
                        {customer.timeline.map((entry) => {
                            const badge = paymentBadge[entry.payment_state];

                            return (
                                <li key={entry.id} className="relative mb-9">
                                    <span className="bg-border absolute top-1.5 -left-[1.8125rem] size-2.5 rounded-full bg-green-400" />

                                    <div className="flex flex-wrap items-center justify-between gap-2">
                                        <p className="text-sm font-medium text-gray-600">{dateTime.format(new Date(entry.occurred_at))}</p>

                                        <div className="flex items-center gap-2">
                                            <Badge variant={badge.variant}>{badge.label}</Badge>
                                            <span className="font-medium tabular-nums">{currency.format(entry.total_amount)}</span>
                                        </div>
                                    </div>

                                    <ul className="mt-2 space-y-1.5 rounded-md border p-3 text-sm">
                                        {entry.items.map((item) => (
                                            <li key={item.id} className="flex items-start justify-between gap-3">
                                                <div className="min-w-0">
                                                    <p className="truncate font-medium">{item.display_name}</p>
                                                    <p className="text-muted-foreground text-xs">
                                                        {item.transaction_label} · {item.quantity}
                                                    </p>
                                                    {/* Only on a cross-brand swap. */}
                                                    {item.returned_name && (
                                                        <p className="text-muted-foreground text-xs">Returned: {item.returned_name}</p>
                                                    )}
                                                </div>

                                                <span className="shrink-0 tabular-nums">{currency.format(item.line_total)}</span>
                                            </li>
                                        ))}
                                    </ul>

                                    {/* Only worth saying when something is still
                                        owed on this particular order. */}
                                    {entry.due_amount > 0 && (
                                        <p className="text-muted-foreground mt-2 text-xs">
                                            Paid {currency.format(entry.paid_amount)} ·{' '}
                                            <span className="text-destructive font-medium">{currency.format(entry.due_amount)} due</span>
                                        </p>
                                    )}
                                </li>
                            );
                        })}
                    </ol>
                )}
            </div>
        </AppLayout>
    );
}
