import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { formatDate, formatDateTime } from '@/lib/datetime';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem, type SharedData } from '@/types';
import { type CustomerProfile, type TimelineCall, type TimelinePayment, type TimelineSale } from '@/types/customer';
import { Head, Link, usePage } from '@inertiajs/react';
import { ArrowLeft, Clock, MapPin, Package, Pencil, Phone, PhoneMissed, Wallet } from 'lucide-react';

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

interface Props {
    customer: CustomerProfile;
    /** Where "back" leads — the call list, if that is where they came from. */
    returnTo: { label: string; href: string };
}

export default function CustomerHistory({ customer, returnTo }: Props) {
    const { auth } = usePage<SharedData>().props;
    const fromCrm = returnTo.href.includes('/crm');

    const breadcrumbs: BreadcrumbItem[] = [
        fromCrm ? { title: 'CRM', href: returnTo.href } : { title: 'Customers', href: '/customers' },
        { title: customer.name, href: '#' },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`${customer.name} — history`} />

            <div className="px-4 py-6">
                <Button variant="outline" asChild className="mb-8 shrink-0 self-start">
                    <Link href={returnTo.href}>
                        <ArrowLeft className="mr-1 size-4" />
                        {returnTo.label}
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

                        {customer.mobile_number && <p className="text-muted-foreground font-medium mt-1 text-sm">{customer.mobile_number}</p>}

                        {customer.address && (
                            <p className="text-muted-foreground mt-1 flex items-start gap-1 text-sm">
                                <MapPin className="mt-0.5 size-3 shrink-0" />
                                {customer.address}
                            </p>
                        )}
                    </div>

                    <Button can={auth.canWrite} variant="outline" size="sm" className="h-8 shrink-0 gap-1 self-start" asChild>
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

                <h2 className="mt-8 font-medium text-lg">History</h2>

                {customer.timeline.length === 0 ? (
                    <div className="mt-4 flex flex-col items-center justify-center rounded-lg border border-dashed px-6 py-12 text-center">
                        <Package className="text-muted-foreground size-8" />
                        <p className="text-muted-foreground mt-3 text-sm">This customer has not bought anything yet.</p>
                    </div>
                ) : (
                    /* The border runs down the left as the timeline spine; each
                       entry hangs its own marker on it. */
                    <ol className="mt-4 sm:ml-4 w-[93%] space-y-6 border-l border-border pl-6 lg:w-xl">
                        {customer.timeline.map((entry) => {
                            switch (entry.kind) {
                                case 'sale':
                                    return <SaleEntry key={`sale-${entry.id}`} entry={entry} />;
                                case 'payment':
                                    return <PaymentEntry key={`payment-${entry.id}`} entry={entry} />;
                                case 'call':
                                    return <CallEntry key={`call-${entry.id}`} entry={entry} />;
                            }
                        })}
                    </ol>
                )}
            </div>
        </AppLayout>
    );
}

/** A sale: what was bought, and what it left owing at the time. */
function SaleEntry({ entry }: { entry: TimelineSale }) {
    const badge = paymentBadge[entry.payment_state];

    return (
        <li className="relative mb-9">
            <span className="absolute top-1.5 -left-[1.8125rem] size-2.5 rounded-full bg-green-400" />

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
                            {/* Only on a cross-brand swap. */}
                            {item.returned_name && <p className="text-muted-foreground text-xs">Returned: {item.returned_name}</p>}
                            <p className="text-muted-foreground text-xs">
                                {item.transaction_label} · {item.quantity}
                            </p>
                        </div>

                        <span className="shrink-0 tabular-nums">{currency.format(item.line_total)}</span>
                    </li>
                ))}
            </ul>

            {/* Only worth saying when the customer left owing something. */}
            {entry.due_amount > 0 && (
                <p className="text-muted-foreground mt-1 text-sm">
                    {/* Ties the sale to the payment entries that settle it,
                        which name the same id. */}
                    <span className="text-foreground mr-1 font-medium tabular-nums text-xs">#{entry.id}</span>
                    <span className="text-xs">Paid {currency.format(entry.paid_amount)}</span> |
                    {entry.settled_later ? (
                        // Says the debt was real at the time but has since been
                        // collected, so it is not mistaken for money still out.
                        <span className="font-medium text-xs ml-1">Due {currency.format(entry.due_amount)}, collected later.</span>
                    ) : (
                        <span className="text-destructive font-medium">Due: {currency.format(entry.due_amount)}</span>
                    )}
                </p>
            )}
        </li>
    );
}

/**
 * Money collected on a later visit.
 *
 * Shown apart from the sale it settles: the customer was seen twice, and the
 * history should say so.
 */
function PaymentEntry({ entry }: { entry: TimelinePayment }) {
    return (
        <li className="relative mb-9">
            <span className="absolute top-1.5 -left-[1.8125rem] size-2.5 rounded-full bg-blue-400" />

            <div className="flex flex-wrap items-center justify-between gap-2">
                <p className="text-sm font-medium text-gray-600">{dateTime.format(new Date(entry.occurred_at))}</p>

                <div className="flex items-center gap-2">
                    <Badge variant="secondary" className="gap-1">
                        <Wallet className="size-3" />
                        Payment
                    </Badge>
                    <span className="font-medium tabular-nums">{currency.format(entry.amount)}</span>
                </div>
            </div>

            <div className="mt-2 rounded-md border p-3 text-sm">
                <p className="text-muted-foreground">
                    Due Payment, Method: {entry.method_label} <br />
                    {/* The id makes the sale findable in the orders list, where
                        the date alone could match several. */}
                    Order ID: <span className="text-foreground font-medium tabular-nums">#{entry.order_id}</span> of{' '}
                    {date.format(new Date(entry.order_occurred_at))}
                </p>

                {/* Says whether this cleared the balance or only reduced it. */}
                <p className="text-muted-foreground mt-1 text-xs">
                    {entry.due_amount > 0 ? (
                        <>
                            <span className="text-destructive font-medium">{currency.format(entry.due_amount)} still due</span> on that sale
                        </>
                    ) : (
                        'That sale is now settled.'
                    )}
                </p>
            </div>
        </li>
    );
}

/**
 * A follow-up call.
 *
 * Sits between the sales rather than in a list of its own, so it can be read
 * against what came before and after: whether the customer bought after being
 * chased is the whole question a call list exists to answer.
 */
function CallEntry({ entry }: { entry: TimelineCall }) {
    // An unanswered call reached nobody, so it is marked as an attempt rather
    // than as contact having been made.
    const Icon = entry.conclusive ? Phone : PhoneMissed;

    return (
        <li className="relative mb-9">
            <span className={`absolute top-1.5 -left-[1.8125rem] size-2.5 rounded-full ${entry.conclusive ? 'bg-amber-400' : 'bg-gray-400'}`} />

            <div className="flex flex-wrap items-center justify-between gap-2">
                <p className="text-sm font-medium text-gray-600">{dateTime.format(new Date(entry.occurred_at))}</p>

                <Badge variant={entry.conclusive ? 'warning' : 'secondary'} className="gap-1">
                    <Icon className="size-3" />
                    Follow-up
                </Badge>
            </div>

            <div className="mt-2 rounded-md border p-3 text-sm">
                <p className="text-muted-foreground font-medium">{entry.outcome_label}</p>

                {entry.note && <p className="text-muted-foreground mt-1 text-xs">{entry.note}</p>}

                {/* A promised callback is a commitment to the customer, so it
                    stays visible in the history rather than only on the list. */}
                {entry.call_again_on && (
                    <p className="text-muted-foreground mt-1 text-xs">
                        Next follow-up: <span className="text-foreground font-medium">{date.format(new Date(entry.call_again_on))}</span>
                    </p>
                )}
            </div>
        </li>
    );
}
