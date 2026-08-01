import { Accordion, AccordionContent, AccordionItem, AccordionTrigger } from '@/components/ui/accordion';
import { Badge, badgeVariants } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { formatDateTime } from '@/lib/datetime';
import { type Order } from '@/types/order';
import { Link } from '@inertiajs/react';
import { type VariantProps } from 'class-variance-authority';
import { Pencil, Wallet } from 'lucide-react';

// narrowSymbol gives the ৳ sign; the default for BDT is the "BDT" code.
const currency = new Intl.NumberFormat('en-BD', {
    style: 'currency',
    currency: 'BDT',
    currencyDisplay: 'narrowSymbol',
    maximumFractionDigits: 0,
});

// Shown in business time: stored timestamps are UTC, so leaving it to the
// browser would render the same sale differently in another timezone.
const date = formatDateTime;

/** Taken from the Badge itself, so adding a variant there needs no change here. */
type BadgeVariant = NonNullable<VariantProps<typeof badgeVariants>['variant']>;

const paymentBadge: Record<Order['payment_state'], { label: string; variant: BadgeVariant }> = {
    paid: { label: 'Paid', variant: 'success' },
    partial: { label: 'Partial', variant: 'warning' },
    due: { label: 'Due', variant: 'destructive' },
};

/** A single recorded sale. */
export default function OrderCard({ order }: { order: Order }) {
    const badge = paymentBadge[order.payment_state];

    return (
        <li className="rounded-lg border-2 px-3 py-2">
            <div className="flex items-start justify-between gap-3">
                <div className="min-w-0">
                    <p className="truncate font-medium">{order.customer.name}</p>
                    {order.customer.mobile_number && (
                        <p className="text-muted-foreground mt-0.5 truncate text-xs">{order.customer.mobile_number}</p>
                    )}
                    <p className="text-muted-foreground mt-0.5 text-xs">{date.format(new Date(order.occurred_at))}</p>
                </div>

                <Badge variant={badge.variant} className="shrink-0">
                    {badge.label}
                </Badge>
            </div>

            <Accordion type="single" collapsible className="mt-2">
                <AccordionItem value="detail" className="border-b-0">
                    <AccordionTrigger className="py-2">
                        <span className="text-muted-foreground font-normal">
                            Total <span className="text-foreground ml-2 font-medium tabular-nums">{currency.format(order.total_amount)}</span>
                        </span>
                    </AccordionTrigger>

                    <AccordionContent className="pb-0">
                        <ul className="mt-3 space-y-2 text-sm">
                            {order.items.map((line) => (
                                <li key={line.id} className="flex items-start justify-between gap-3">
                                    <div className="min-w-0">
                                        <p className="truncate font-medium">{line.display_name}</p>
                                        <p className="text-muted-foreground text-xs">
                                            {line.transaction_label} · {line.quantity} × {currency.format(line.unit_price)}
                                        </p>
                                        {/* Only when the cylinder was sold
                                            outright, so the customer paid for
                                            the shell and its gas at once. */}
                                        {line.cylinder_price !== null && line.gas_price !== null && (
                                            <p className="text-muted-foreground text-xs">
                                                Gas: {currency.format(line.gas_price)}, Cylinder: {currency.format(line.cylinder_price)}
                                            </p>
                                        )}

                                        {/* Only on a cross-brand swap. */}
                                        {line.returned_name && (
                                            <p className="text-muted-foreground text-xs">Returned: {line.returned_name}</p>
                                        )}
                                    </div>

                                    <span className="shrink-0 font-medium tabular-nums">{currency.format(line.line_total)}</span>
                                </li>
                            ))}
                        </ul>

                        {(order.due_amount || order.is_editable) &&
                            <div className="mt-3 flex items-end justify-between gap-3 border-t pt-3">
                                <dl className="flex flex-wrap gap-x-6 gap-y-3 text-sm">
                                    {order.due_amount > 0 && (
                                        <>
                                            <div>
                                                <dt className="text-muted-foreground text-xs">Paid</dt>
                                                <dd className="mt-0.5 font-medium tabular-nums">{currency.format(order.paid_amount)}</dd>
                                            </div>
                                            <div>
                                                <dt className="text-muted-foreground text-xs">Due</dt>
                                                <dd className="text-destructive mt-0.5 font-medium tabular-nums">
                                                    {currency.format(order.due_amount)}
                                                </dd>
                                            </div>
                                        </>
                                    )}
                                </dl>

                                <div className="flex shrink-0 items-center gap-2">
                                    {/* Only a sale with a balance can be settled. */}
                                    {order.due_amount > 0 && (
                                        <Button size="sm" className="h-7 gap-1 p-2" asChild>
                                            <Link href={`/orders/pay/${order.id}`}>
                                                <Wallet className="size-3" />
                                                Pay
                                            </Link>
                                        </Button>
                                    )}

                                    {order.is_editable && (
                                        <Button variant="outline" size="sm" className="h-7 gap-1 p-2" asChild>
                                            <Link href={`/orders/edit/${order.id}`}>
                                                <Pencil className="size-3" />
                                                Edit
                                            </Link>
                                        </Button>
                                    )}
                                </div>
                            </div>
                        }
                    </AccordionContent>
                </AccordionItem>
            </Accordion>
        </li>
    );
}
