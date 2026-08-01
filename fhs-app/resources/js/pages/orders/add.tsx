import InputError from '@/components/input-error';
import OrderLineFields, { type OrderLineValues } from '@/components/orders/order-line-fields';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Switch } from '@/components/ui/switch';
import { businessInputToUtc, businessNow, toBusinessInputValue } from '@/lib/datetime';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { type CustomerLookup, type OrderFormValues, type SellableItem, type TransactionTypeOption } from '@/types/order';
import { Head, Link, useForm } from '@inertiajs/react';
import { ArrowLeft, Plus } from 'lucide-react';
import { FormEventHandler, useState } from 'react';

/**
 * Now, in the shape a datetime-local input expects, in business time.
 *
 * The input has no timezone of its own, so the value has to be converted before
 * it goes in — otherwise staff would be typing against a UTC clock while every
 * date on screen reads in business time.
 */
const now = businessNow;

const emptyLine: OrderLineValues = {
    catalogue_id: '',
    transaction_type: '',
    returned_catalogue_id: '',
    quantity: '1',
    unit_price: '',
    cylinder_price: '',
};

interface OrderAddProps {
    items: SellableItem[];
    transactionTypes: TransactionTypeOption[];
    /** Absent when recording a sale; present when correcting one. */
    order?: OrderFormValues;
    /** Why this sale can no longer be corrected, when it cannot. */
    blockedReason?: string | null;
}

export default function OrderForm({ items, transactionTypes, order, blockedReason }: OrderAddProps) {
    const isEditing = order !== undefined;
    const isLocked = Boolean(blockedReason);

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Orders', href: '/orders' },
        isEditing ? { title: 'Edit', href: '#' } : { title: 'Add', href: '/orders/add' },
    ];

    const { data, setData, post, patch, transform, errors, processing } = useForm({
        mobile_number: order?.mobile_number ?? '',
        customer_name: order?.customer_name ?? '',
        address: order?.address ?? '',
        // Arrives as UTC; the input shows business time, so it is converted on
        // the way in as well as on the way out.
        occurred_at: order ? toBusinessInputValue(`${order.occurred_at}:00Z`) : now(),
        items: (order?.items ?? [{ ...emptyLine }]) as OrderLineValues[],
        is_paid: order?.is_paid ?? true,
        amount_paid: order?.amount_paid ?? '',
        payment_method: order?.payment_method ?? 'cash',
    });

    // What a known customer already owes, shown so staff can mention it at the
    // door. Null until a number is looked up.
    const [knownCustomer, setKnownCustomer] = useState<CustomerLookup | null>(null);

    // A cylinder sold outright is priced as shell plus gas, so both count
    // toward what the customer pays for that line.
    const sellsShell = (line: OrderLineValues) =>
        transactionTypes.find((option) => option.value === line.transaction_type)?.sells_shell ?? false;

    const total = data.items.reduce((sum, line) => {
        const unit = (Number(line.unit_price) || 0) + (sellsShell(line) ? Number(line.cylinder_price) || 0 : 0);

        return sum + (Number(line.quantity) || 0) * unit;
    }, 0);

    /**
     * Fill in a customer already on file.
     *
     * Not finding one is the normal case for a new customer, so it clears the
     * banner rather than reporting an error.
     */
    const lookupCustomer = async () => {
        const mobile = data.mobile_number.trim();

        if (mobile === '') {
            setKnownCustomer(null);

            return;
        }

        const response = await fetch(`/orders/customer-lookup?mobile_number=${encodeURIComponent(mobile)}`, {
            headers: { Accept: 'application/json' },
        });

        if (!response.ok) {
            return;
        }

        const { customer } = (await response.json()) as { customer: CustomerLookup | null };

        setKnownCustomer(customer);

        if (customer) {
            setData((current) => ({ ...current, customer_name: customer.name, address: customer.address ?? '' }));
        }
    };

    const updateLine = (index: number, field: keyof OrderLineValues, value: string) => {
        setData(
            'items',
            data.items.map((line, i) => (i === index ? { ...line, [field]: value } : line)),
        );
    };

    const addLine = () => setData('items', [...data.items, { ...emptyLine }]);

    const removeLine = (index: number) => setData('items', data.items.filter((_, i) => i !== index));

    const submit: FormEventHandler = (e) => {
        e.preventDefault();

        if (isLocked) {
            return;
        }

        // The date field works in business time, but timestamps are stored in
        // UTC. Converting here rather than in setData keeps the input showing
        // what was typed; transform only rewrites what is sent.
        transform((payload) => ({
            ...payload,
            occurred_at: businessInputToUtc(payload.occurred_at),
        }));

        if (isEditing) {
            // Correcting rebuilds the sale server-side and redirects to the
            // list, so there is nothing to reset here.
            patch(`/orders/update/${order.id}`, { preserveScroll: true });

            return;
        }

        post('/orders', { preserveScroll: true });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={isEditing ? 'Edit sale' : 'Record a sale'} />

            <div className="px-4 py-6">
                <Button variant="outline" asChild className="mb-10 shrink-0 self-start">
                    <Link href="/orders">
                        <ArrowLeft className="mr-1 size-4" />
                        Back to Orders
                    </Link>
                </Button>

                {/* A correction rewrites stock and payment, so it is worth
                    saying what saving will actually do. */}
                {isEditing && !isLocked && (
                    <div className="mb-6 rounded-md border border-dashed px-4 py-3">
                        <p className="text-sm font-medium">Correcting a recorded sale</p>
                        <p className="text-muted-foreground mt-1 text-sm">
                            The stock this sale moved is returned and re-applied from the corrected figures.
                        </p>
                    </div>
                )}

                {isLocked && (
                    <div className="border-destructive/50 bg-destructive/5 mb-6 rounded-md border px-4 py-3">
                        <p className="text-sm font-medium">This sale can no longer be corrected</p>
                        <p className="text-muted-foreground mt-1 text-sm">{blockedReason}</p>
                    </div>
                )}

                <div className="mt-6 grid gap-10 lg:grid-cols-[minmax(0,50rem)_minmax(0,1fr)]">
                    <form onSubmit={submit} className="space-y-6">
                        <div className="grid gap-4 sm:grid-cols-2">
                            <div className="grid gap-2">
                                <Label htmlFor="mobile_number">
                                    Mobile number <span className="text-muted-foreground font-normal">(optional)</span>
                                </Label>
                                <Input
                                    id="mobile_number"
                                    value={data.mobile_number}
                                    onChange={(e) => setData('mobile_number', e.target.value)}
                                    onBlur={lookupCustomer}
                                    placeholder="01700000000"
                                />
                                <p className="text-muted-foreground text-xs">A known number fills in the rest.</p>
                                <InputError message={errors.mobile_number} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="customer_name">
                                    Customer name <span className="text-muted-foreground font-normal">(optional)</span>
                                </Label>
                                <Input
                                    id="customer_name"
                                    value={data.customer_name}
                                    onChange={(e) => setData('customer_name', e.target.value)}
                                    placeholder="Name"
                                />
                                <InputError message={errors.customer_name} />
                            </div>

                            <div className="grid gap-2 sm:col-span-2">
                                <Label htmlFor="address">
                                    Address <span className="text-muted-foreground font-normal">(optional)</span>
                                </Label>
                                <Input
                                    id="address"
                                    value={data.address}
                                    onChange={(e) => setData('address', e.target.value)}
                                    placeholder="Delivery address"
                                />
                                <InputError message={errors.address} />
                            </div>
                        </div>

                        {/* Worth surfacing at the door: this customer already
                            owes money from earlier orders. */}
                        {knownCustomer && knownCustomer.outstanding_balance > 0 && (
                            <div className="border-destructive/50 bg-destructive/5 rounded-md border px-4 py-3">
                                <p className="text-sm font-medium">Existing balance</p>
                                <p className="text-muted-foreground mt-1 text-sm">
                                    This customer already owes ৳{knownCustomer.outstanding_balance.toFixed(2)} from previous orders.
                                </p>
                            </div>
                        )}

                        <div className="grid gap-2">
                            <div className="flex items-center justify-between gap-2">
                                <Label>Products</Label>

                                <Button type="button" variant="outline" size="sm" className="h-7 gap-1 p-2" onClick={addLine}>
                                    <Plus className="size-3" />
                                    Add product
                                </Button>
                            </div>

                            <ul className="grid gap-3 border-2 rounded">
                                {data.items.map((line, index) => (
                                    <OrderLineFields
                                        key={index}
                                        line={line}
                                        index={index}
                                        items={items}
                                        transactionTypes={transactionTypes}
                                        errors={errors as Record<string, string | undefined>}
                                        onChange={updateLine}
                                        onRemove={removeLine}
                                        canRemove={data.items.length > 1}
                                    />
                                ))}
                            </ul>

                            <InputError message={errors.items} />
                        </div>

                        <div className="rounded-md border-2 p-4">
                            <div className="flex items-center justify-between gap-4">
                                <div>
                                    <p className="text-muted-foreground text-sm">Total</p>
                                    <p className="text-xl font-semibold tabular-nums">৳{total.toFixed(2)}</p>
                                </div>

                                <div className="flex items-center gap-3">
                                    <Label htmlFor="is_paid" className="font-medium">
                                        Paid
                                    </Label>
                                    <Switch
                                        id="is_paid"
                                        checked={data.is_paid}
                                        onCheckedChange={(checked) => setData('is_paid', checked)}
                                    />
                                </div>
                            </div>

                            <div className="mt-4 grid gap-4 border-t pt-4 sm:grid-cols-2">
                                {/* Switch off means not paid in full. Zero is a
                                    valid entry — it records a sale on credit. */}
                                {!data.is_paid && (
                                    <div className="grid gap-2">
                                        <Label htmlFor="amount_paid">Amount received</Label>
                                        <Input
                                            id="amount_paid"
                                            type="text"
                                            value={data.amount_paid}
                                            onChange={(e) => setData('amount_paid', e.target.value)}
                                            placeholder="0.00"
                                        />
                                        <p className="text-muted-foreground text-xs">
                                            Leave at 0 if nothing was paid. The rest becomes the customer's balance.
                                        </p>
                                        <InputError message={errors.amount_paid} />
                                    </div>
                                )}

                                {/* Asked regardless: money changes hands on a
                                    paid-in-full sale too, and it may be either. */}
                                <div className="grid gap-2">
                                    <Label htmlFor="payment_method">Method</Label>
                                    <Select value={data.payment_method} onValueChange={(value) => setData('payment_method', value)}>
                                        <SelectTrigger id="payment_method">
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="cash">Cash</SelectItem>
                                            <SelectItem value="mobile">Mobile payment</SelectItem>
                                        </SelectContent>
                                    </Select>
                                    <InputError message={errors.payment_method} />
                                </div>
                            </div>
                        </div>

                        <div className="grid gap-2 sm:max-w-xs">
                            <Label htmlFor="occurred_at">Sale date and time</Label>
                            <Input
                                id="occurred_at"
                                className="block"
                                type="datetime-local"
                                value={data.occurred_at}
                                onChange={(e) => setData('occurred_at', e.target.value)}
                                // Stops the picker offering a future moment.
                                // The server rejects one regardless.
                                max={now()}
                                required
                            />
                            <p className="text-muted-foreground text-xs">
                                When the sale happened, which may be earlier than when it is being entered.
                            </p>
                            <InputError message={errors.occurred_at} />
                        </div>

                        <div className="flex items-center gap-3">
                            <Button disabled={processing || total <= 0 || isLocked}>
                                {isEditing ? 'Save correction' : 'Record sale'}
                            </Button>

                            {isEditing && (
                                <Button variant="secondary" className="px-6" asChild>
                                    <Link href="/orders">Cancel</Link>
                                </Button>
                            )}
                        </div>
                    </form>
                </div>
            </div>
        </AppLayout>
    );
}
