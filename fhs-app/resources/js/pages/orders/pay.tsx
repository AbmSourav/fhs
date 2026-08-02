import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { businessInputToUtc, businessNow, formatDate, formatDateTime } from '@/lib/datetime';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { type OrderPayment } from '@/types/order';
import { Head, Link, useForm } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
import { FormEventHandler } from 'react';

// narrowSymbol gives the ৳ sign; the default for BDT is the "BDT" code.
const currency = new Intl.NumberFormat('en-BD', {
    style: 'currency',
    currency: 'BDT',
    currencyDisplay: 'narrowSymbol',
    maximumFractionDigits: 0,
});

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Orders', href: '/orders' },
    { title: 'Payment', href: '#' },
];

export default function OrderPay({ order }: { order: OrderPayment }) {
    const { data, setData, post, transform, errors, processing } = useForm({
        // Settling in full is the common case, so the balance is prefilled.
        amount: String(order.due_amount),
        method: 'cash',
        paid_at: businessNow(),
    });

    const amount = Number(data.amount) || 0;
    const remaining = Math.max(order.due_amount - amount, 0);

    const submit: FormEventHandler = (e) => {
        e.preventDefault();

        // The date field works in business time, but timestamps are stored in
        // UTC. transform rewrites only what is sent, leaving the input alone.
        transform((payload) => ({
            ...payload,
            paid_at: businessInputToUtc(payload.paid_at),
        }));

        post(`/orders/pay/${order.id}`, { preserveScroll: true });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Record a payment" />

            <div className="px-4 py-6">
                <Button variant="outline" asChild className="mb-8 shrink-0 self-start">
                    <Link href="/orders">
                        <ArrowLeft className="mr-1 size-4" />
                        Back to Orders
                    </Link>
                </Button>

                <div className="grid gap-10 lg:grid-cols-[minmax(0,28rem)_minmax(0,1fr)]">
                    <div>
                        <div className="rounded-lg border p-4">
                            <p className="font-medium">{order.customer.name}</p>
                            {order.customer.mobile_number && (
                                <p className="text-muted-foreground mt-0.5 text-sm">{order.customer.mobile_number}</p>
                            )}
                            <p className="text-muted-foreground mt-0.5 text-xs">{formatDate.format(new Date(order.occurred_at))}</p>

                            <dl className="mt-4 flex flex-wrap gap-x-6 gap-y-3 border-t pt-3 text-sm">
                                <div>
                                    <dt className="text-muted-foreground text-xs">Total</dt>
                                    <dd className="mt-0.5 font-medium tabular-nums">{currency.format(order.total_amount)}</dd>
                                </div>
                                <div>
                                    <dt className="text-muted-foreground text-xs">Paid</dt>
                                    <dd className="mt-0.5 font-medium tabular-nums">{currency.format(order.paid_amount)}</dd>
                                </div>
                                <div>
                                    <dt className="text-muted-foreground text-xs">Due</dt>
                                    <dd className="text-destructive mt-0.5 font-medium tabular-nums">{currency.format(order.due_amount)}</dd>
                                </div>
                            </dl>
                        </div>

                        <form onSubmit={submit} className="mt-6 space-y-6">
                            <div className="grid gap-2">
                                <Label htmlFor="amount">Amount received</Label>
                                <Input
                                    id="amount"
                                    type="text"
                                    value={data.amount}
                                    onChange={(e) => setData('amount', e.target.value)}
                                    placeholder="0.00"
                                    required
                                />
                                {/* Part payment is normal here, so it is worth
                                    saying what will still be owed. */}
                                {amount > 0 && remaining > 0 && (
                                    <p className="text-muted-foreground text-xs">
                                        {currency.format(remaining)} will still be owed after this.
                                    </p>
                                )}
                                <InputError message={errors.amount} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="method">Method</Label>
                                <Select value={data.method} onValueChange={(value) => setData('method', value)}>
                                    <SelectTrigger id="method">
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="cash">Cash</SelectItem>
                                        <SelectItem value="mobile">MFS</SelectItem>
                                    </SelectContent>
                                </Select>
                                <InputError message={errors.method} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="paid_at">Received at</Label>
                                <Input
                                    id="paid_at"
                                    className="block"
                                    type="datetime-local"
                                    value={data.paid_at}
                                    onChange={(e) => setData('paid_at', e.target.value)}
                                    // Stops the picker offering a future moment.
                                    // The server rejects one regardless.
                                    max={businessNow()}
                                    required
                                />
                                <InputError message={errors.paid_at} />
                            </div>

                            <div className="flex items-center gap-3">
                                <Button disabled={processing || amount <= 0}>Record payment</Button>

                                <Button variant="secondary" className="px-6" asChild>
                                    <Link href="/orders">Cancel</Link>
                                </Button>
                            </div>
                        </form>
                    </div>

                    {/* What has already been received, so a customer paying in
                        instalments can see where they are up to. */}
                    {order.payments.length > 0 && (
                        <div>
                            <h2 className="font-medium">Payments so far</h2>

                            <ul className="mt-3 space-y-2 text-sm">
                                {order.payments.map((payment) => (
                                    <li key={payment.id} className="flex items-center justify-between gap-3 rounded-md border p-3">
                                        <div>
                                            <p className="font-medium tabular-nums">{currency.format(payment.amount)}</p>
                                            <p className="text-muted-foreground text-xs">{payment.method}</p>
                                        </div>

                                        <span className="text-muted-foreground text-xs">
                                            {formatDateTime.format(new Date(payment.paid_at))}
                                        </span>
                                    </li>
                                ))}
                            </ul>
                        </div>
                    )}
                </div>
            </div>
        </AppLayout>
    );
}
