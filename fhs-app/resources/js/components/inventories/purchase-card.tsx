import { Accordion, AccordionContent, AccordionItem, AccordionTrigger } from '@/components/ui/accordion';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { BUSINESS_TIME_ZONE, formatDate } from '@/lib/datetime';
import { type InventoryPurchase } from '@/types/inventory';
import { Link } from '@inertiajs/react';
import { Pencil, RotateCcw } from 'lucide-react';

// narrowSymbol gives the ৳ sign; the default for BDT is the "BDT" code.
const currency = new Intl.NumberFormat('en-BD', {
    style: 'currency',
    currency: 'BDT',
    currencyDisplay: 'narrowSymbol',
    maximumFractionDigits: 0,
});

// Shown in business time: stored timestamps are UTC, so leaving it to the
// browser would render the same purchase differently in another timezone.
const date = formatDate;

// A correction happens at a moment, unlike purchased_at which is a date the
// user picks — so this one carries the time as well.
const dateTime = new Intl.DateTimeFormat('en-GB', {
    weekday: 'short',
    day: 'numeric',
    month: 'short',
    year: '2-digit',
    hour: '2-digit',
    minute: '2-digit',
    timeZone: BUSINESS_TIME_ZONE,
});

/** A single recorded purchase. */
export default function PurchaseCard({ purchase }: { purchase: InventoryPurchase }) {
    const { catalogue } = purchase;

    const title = (catalogue.is_gas ? catalogue.brand_name : null) ?? purchase.display_name;
    const productDetail = [catalogue.type_label, `${catalogue.weight}kg`].filter(Boolean).join(' · ');

    return (
        <li className="rounded-lg border-2 py-2 px-3">
            <div className="flex items-start justify-between gap-3">
                <div className="min-w-0">
                    <p className="truncate font-medium">{title}</p>
                    <p className="text-muted-foreground mt-0.5 truncate text-xs">{productDetail}</p>
                    <p className="text-muted-foreground mt-0.5 text-xs">{date.format(new Date(purchase.purchased_at))}</p>
                </div>

                {purchase.is_refill && (
                    <Badge variant="secondary" className="shrink-0 gap-1">
                        <RotateCcw className="size-3" />
                        Swap
                    </Badge>
                )}
            </div>

            <Accordion type="single" collapsible className="mt-2">
                {/* The value only has to be unique within this one card, since
                    each card is its own accordion root. */}
                <AccordionItem value="detail" className="border-b-0">
                    <AccordionTrigger className="py-2">
                        {/* The total stays visible when collapsed — it is the
                            one number worth scanning a list of purchases for. */}
                        <span className="text-muted-foreground font-normal">
                            Total <span className="text-foreground font-medium tabular-nums ml-2">{currency.format(purchase.total_cost)}</span>
                        </span>
                    </AccordionTrigger>

                    <AccordionContent className="pb-0">
                        <dl className="flex flex-wrap gap-x-6 gap-y-5 text-sm mt-3">
                            <div>
                                <dt className="text-muted-foreground text-xs">Filled</dt>
                                <dd className="mt-0.5 font-medium tabular-nums">{purchase.filled_quantity}</dd>
                            </div>

                            {/* Only returnable products have empty shells to count. */}
                            {catalogue.is_returnable && (
                                <div>
                                    <dt className="text-muted-foreground text-xs">{purchase.is_refill ? 'Empties sent' : 'Empty'}</dt>
                                    <dd className="mt-0.5 font-medium tabular-nums">{purchase.empty_quantity}</dd>
                                </div>
                            )}

                            {/* Set only on a cross-brand swap: the empties that
                                went back were a different product. */}
                            {purchase.swapped_for && (
                                <div>
                                    <dt className="text-muted-foreground text-xs">Empties brand</dt>
                                    <dd className="mt-0.5 font-medium">{purchase.swapped_for}</dd>
                                </div>
                            )}

                            <div>
                                <dt className="text-muted-foreground text-xs">{catalogue.is_gas ? 'Gas cost' : 'Unit cost'}</dt>
                                <dd className="mt-0.5 font-medium tabular-nums">{currency.format(purchase.unit_cost)}</dd>
                            </div>

                            {/* A refill buys gas into shells already owned, so it
                                carries no shell cost — a zero would just be noise. */}
                            {purchase.shell_unit_cost > 0 && (
                                <div>
                                    <dt className="text-muted-foreground text-xs">Shell cost</dt>
                                    <dd className="mt-0.5 font-medium tabular-nums">{currency.format(purchase.shell_unit_cost)}</dd>
                                </div>
                            )}
                        </dl>

                        {purchase.transport_cost > 0 && (
                            <dl className="mt-5 flex flex-wrap gap-x-6 gap-y-5 text-sm">
                                <div>
                                    <dt className="text-muted-foreground text-xs">Transport</dt>
                                    <dd className="mt-0.5 font-medium tabular-nums">{currency.format(purchase.transport_cost)}</dd>
                                </div>
                            </dl>
                        )}

                        {(purchase.supplier || purchase.invoice_ref) && (
                            <p className="text-muted-foreground mt-3 truncate border-t pt-3 text-xs">
                                <span className="mr-1">Supplier</span>
                                <span className="text-foreground font-medium tabular-nums">
                                    {purchase.supplier}
                                    {purchase.supplier && purchase.invoice_ref && (<span className="text-muted-foreground">, Invoice: </span>)}
                                    {purchase.invoice_ref}
                                </span>
                            </p>
                        )}

                        <div className="mt-3 flex items-center justify-between gap-3 border-t pt-3">
                            <p className="text-muted-foreground text-xs">
                                {purchase.edited_at ? `Updated: ${dateTime.format(new Date(purchase.edited_at))}` : ''}
                            </p>

                            {purchase.is_editable && (
                                <Button variant="outline" className="p-2 h-7 gap-1" size="sm" asChild>
                                    <Link href={`/inventories/edit/${purchase.kind}/${purchase.id}`}>
                                        <Pencil className="size-3" />
                                        Edit
                                    </Link>
                                </Button>
                            )}
                        </div>
                    </AccordionContent>
                </AccordionItem>
            </Accordion>
        </li>
    );
}
