import { Accordion, AccordionContent, AccordionItem, AccordionTrigger } from '@/components/ui/accordion';
import { Badge } from '@/components/ui/badge';
import { type Order } from '@/types/order';

// narrowSymbol gives the ৳ sign; the default for BDT is the "BDT" code.
const currency = new Intl.NumberFormat('en-BD', {
    style: 'currency',
    currency: 'BDT',
    currencyDisplay: 'narrowSymbol',
    maximumFractionDigits: 0,
});

// en-GB puts the day before the month, giving "Wed 29 Jul 2026".
const date = new Intl.DateTimeFormat('en-GB', {
    weekday: 'short',
    day: 'numeric',
    month: 'short',
    year: 'numeric',
});

const paymentBadge: Record<Order['payment_state'], { label: string; variant: 'default' | 'secondary' | 'destructive' }> = {
    paid: { label: 'Paid', variant: 'secondary' },
    partial: { label: 'Partial', variant: 'default' },
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
                        {/* The total stays visible when collapsed — it is the
                            one number worth scanning a list of sales for. */}
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
                                        {/* Only on a cross-brand swap. */}
                                        {line.returned_name && (
                                            <p className="text-muted-foreground text-xs">Returned: {line.returned_name}</p>
                                        )}
                                    </div>

                                    <span className="shrink-0 font-medium tabular-nums">{currency.format(line.line_total)}</span>
                                </li>
                            ))}
                        </ul>

                        {/* Only worth showing when something is still owed —
                            a fully paid order says all it needs to above. */}
                        {order.due_amount > 0 && (
                            <dl className="mt-3 flex flex-wrap gap-x-6 gap-y-3 border-t pt-3 text-sm">
                                <div>
                                    <dt className="text-muted-foreground text-xs">Paid</dt>
                                    <dd className="mt-0.5 font-medium tabular-nums">{currency.format(order.paid_amount)}</dd>
                                </div>
                                <div>
                                    <dt className="text-muted-foreground text-xs">Due</dt>
                                    <dd className="text-destructive mt-0.5 font-medium tabular-nums">{currency.format(order.due_amount)}</dd>
                                </div>
                            </dl>
                        )}
                    </AccordionContent>
                </AccordionItem>
            </Accordion>
        </li>
    );
}
