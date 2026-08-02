import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import AppLayout from '@/layouts/app-layout';
import { businessInputToUtc, businessNow, toBusinessInputValue } from '@/lib/datetime';
import { type BreadcrumbItem } from '@/types';
import { type ExpenseFormValues, type ExpenseOption } from '@/types/expense';
import { Head, Link, useForm } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
import { FormEventHandler } from 'react';

interface Props {
    /** Absent when recording a new expense. */
    expense?: ExpenseFormValues;
    categories: ExpenseOption[];
    methods: ExpenseOption[];
    /** Set when the correction window has already closed. */
    editBlockedReason?: string | null;
}

export default function ExpenseForm({ expense, categories, methods, editBlockedReason }: Props) {
    const isEditing = expense !== undefined;

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Other Expenses', href: '/expenses' },
        { title: isEditing ? 'Edit' : 'Add', href: '#' },
    ];

    const { data, setData, post, patch, transform, errors, processing } = useForm({
        category: expense?.category ?? categories[0]?.value ?? '',
        description: expense?.description ?? '',
        amount: expense?.amount ?? '',
        paid_to: expense?.paid_to ?? '',
        payment_method: expense?.payment_method ?? methods[0]?.value ?? '',
        receipt_ref: expense?.receipt_ref ?? '',
        // Stored UTC, edited in business time.
        spent_at: expense ? toBusinessInputValue(expense.spent_at) : businessNow(),
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();

        // The date field works in business time, but timestamps are stored in
        // UTC. transform rewrites only what is sent, leaving the input alone.
        transform((payload) => ({
            ...payload,
            spent_at: businessInputToUtc(payload.spent_at),
        }));

        if (isEditing) {
            patch(`/expenses/update/${expense.id}`, { preserveScroll: true });

            return;
        }

        post('/expenses', { preserveScroll: true });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={isEditing ? 'Edit expense' : 'Record an expense'} />

            <div className="px-4 py-6">
                <Button variant="outline" asChild className="mb-8 shrink-0 self-start">
                    <Link href="/expenses">
                        <ArrowLeft className="mr-1 size-4" />
                        Back to Other Expenses
                    </Link>
                </Button>

                <div>
                    <h1 className="text-xl font-semibold">{isEditing ? 'Edit expense' : 'Record an expense'}</h1>
                    <p className="text-muted-foreground mt-1 text-sm">Spending that is not stock to sell</p>
                </div>

                {/* Said up front rather than on submit, so the form is not
                    filled in only to be rejected. */}
                {editBlockedReason && (
                    <p className="text-destructive mt-4 text-sm">{editBlockedReason}</p>
                )}

                <div className="mt-6 grid gap-10 lg:grid-cols-[minmax(0,28rem)_minmax(0,1fr)]">
                    <form onSubmit={submit} className="space-y-6">
                        <div className="grid gap-2">
                            <Label htmlFor="description">What was it for?</Label>
                            <Input
                                id="description"
                                value={data.description}
                                onChange={(e) => setData('description', e.target.value)}
                                placeholder="Van fuel, October wages, padlock"
                                required
                            />
                            <InputError message={errors.description} />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="amount">Amount</Label>
                            <Input
                                id="amount"
                                type="text"
                                value={data.amount}
                                onChange={(e) => setData('amount', e.target.value)}
                                placeholder="0.00"
                                required
                            />
                            <InputError message={errors.amount} />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="category">Category</Label>
                            <Select value={data.category} onValueChange={(value) => setData('category', value)}>
                                <SelectTrigger id="category">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    {categories.map((option) => (
                                        <SelectItem key={option.value} value={option.value}>
                                            {option.label}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            <InputError message={errors.category} />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="payment_method">Paid by</Label>
                            <Select value={data.payment_method} onValueChange={(value) => setData('payment_method', value)}>
                                <SelectTrigger id="payment_method">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    {methods.map((option) => (
                                        <SelectItem key={option.value} value={option.value}>
                                            {option.label}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            <InputError message={errors.payment_method} />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="spent_at">When</Label>
                            <Input
                                id="spent_at"
                                className="block"
                                type="datetime-local"
                                value={data.spent_at}
                                onChange={(e) => setData('spent_at', e.target.value)}
                                // Stops the picker offering a future moment.
                                // The server rejects one regardless.
                                max={businessNow()}
                                required
                            />
                            <InputError message={errors.spent_at} />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="paid_to">
                                Paid to <span className="text-muted-foreground font-normal">(optional)</span>
                            </Label>
                            <Input
                                id="paid_to"
                                value={data.paid_to}
                                onChange={(e) => setData('paid_to', e.target.value)}
                                placeholder="Supplier or person"
                            />
                            <InputError message={errors.paid_to} />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="receipt_ref">
                                Receipt reference <span className="text-muted-foreground font-normal">(optional)</span>
                            </Label>
                            <Input
                                id="receipt_ref"
                                value={data.receipt_ref}
                                onChange={(e) => setData('receipt_ref', e.target.value)}
                                placeholder="Invoice or bill number"
                            />
                            <InputError message={errors.receipt_ref} />
                        </div>

                        <div className="flex items-center gap-3">
                            <Button disabled={processing}>{isEditing ? 'Save changes' : 'Record expense'}</Button>

                            <Button variant="secondary" className="px-6" asChild>
                                <Link href="/expenses">Cancel</Link>
                            </Button>
                        </div>
                    </form>
                </div>
            </div>
        </AppLayout>
    );
}
