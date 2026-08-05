import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Dialog, DialogClose, DialogContent, DialogDescription, DialogFooter, DialogTitle, DialogTrigger } from '@/components/ui/dialog';
import { formatDateTime } from '@/lib/datetime';
import { type Expense } from '@/types/expense';
import { type SharedData } from '@/types';
import { Link, useForm, usePage } from '@inertiajs/react';
import { Pencil, Trash2 } from 'lucide-react';
import { FormEventHandler } from 'react';

// narrowSymbol gives the ৳ sign; the default for BDT is the "BDT" code.
const currency = new Intl.NumberFormat('en-BD', {
    style: 'currency',
    currency: 'BDT',
    currencyDisplay: 'narrowSymbol',
    maximumFractionDigits: 0,
});

// Shown in business time: stored timestamps are UTC, so leaving it to the
// browser would render the same expense differently in another timezone.
const date = formatDateTime;

/** A single recorded expense. */
export default function ExpenseCard({ expense }: { expense: Expense }) {
    const { auth } = usePage<SharedData>().props;
    const { delete: destroy, processing } = useForm();

    const remove: FormEventHandler = (e) => {
        e.preventDefault();

        destroy(`/expenses/${expense.id}`, { preserveScroll: true });
    };

    const footerBorder = auth.canWrite ? 'border-t' : '';

    return (
        <li className="rounded-lg border-2 px-3 py-2">
            <div className="flex items-start justify-between gap-3">
                <div className="min-w-0">
                    <p className="truncate font-medium">{expense.description}</p>
                    <p className="text-muted-foreground mt-0.5 text-xs">{date.format(new Date(expense.spent_at))}</p>
                </div>

                <span className="shrink-0 font-medium tabular-nums">{currency.format(expense.amount)}</span>
            </div>

            <div className="mt-2 flex flex-wrap items-center gap-2">
                <Badge variant="secondary">{expense.category_label}</Badge>
                <span className="text-muted-foreground text-xs">{expense.method_label}</span>
            </div>

            {/* Only when someone or something was named on the receipt. */}
            {(expense.paid_to || expense.receipt_ref) && (
                <p className="text-muted-foreground mt-2 truncate text-xs">
                    {expense.paid_to && <>Paid to {expense.paid_to}</>}
                    {expense.paid_to && expense.receipt_ref && ' · '}
                    {expense.receipt_ref && <>Ref {expense.receipt_ref}</>}
                </p>
            )}

            <div className={`mt-3 flex items-center justify-end gap-2 ${footerBorder} pt-3`}>
                {/* Correcting closes after an hour; deleting never does. */}
                {expense.is_editable && (
                    <Button can={auth.canWrite} variant="outline" size="sm" className="h-7 gap-1 p-2" asChild>
                        <Link href={`/expenses/edit/${expense.id}`}>
                            <Pencil className="size-3" />
                            Edit
                        </Link>
                    </Button>
                )}

                <Dialog>
                    <DialogTrigger asChild>
                        <Button can={auth.canWrite} variant="ghost" size="sm" className="text-destructive h-7 gap-1 p-2">
                            <Trash2 className="size-3" />
                            Delete
                        </Button>
                    </DialogTrigger>

                    <DialogContent className="w-[95%]">
                        <DialogTitle>Delete this expense?</DialogTitle>
                        <DialogDescription>
                            <span className="font-bold">{expense.description} — {currency.format(expense.amount)}</span>. <br />It will stop counting toward reported spending.
                        </DialogDescription>

                        <form onSubmit={remove}>
                            <DialogFooter>
                                <DialogClose asChild>
                                    <Button variant="secondary">Cancel</Button>
                                </DialogClose>

                                <Button variant="destructive" disabled={processing} asChild>
                                    <button type="submit">Delete expense</button>
                                </Button>
                            </DialogFooter>
                        </form>
                    </DialogContent>
                </Dialog>
            </div>
        </li>
    );
}
