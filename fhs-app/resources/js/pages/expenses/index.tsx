import ExpenseCard from '@/components/expenses/expense-card';
import PaginationNav from '@/components/pagination-nav';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { type Expense } from '@/types/expense';
import { type Paginated } from '@/types/pagination';
import { Head, Link } from '@inertiajs/react';
import { Plus, Receipt } from 'lucide-react';

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Other Expenses', href: '/expenses' }];

export default function ExpensesIndex({ expenses }: { expenses: Paginated<Expense> }) {
    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Other Expenses" />

            <div className="px-4 py-6">
                <div className="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                    <div>
                        <h1 className="text-xl font-semibold">Other Expenses</h1>
                        <p className="text-muted-foreground mt-1 text-sm">Spending outside of stock, newest first</p>
                    </div>

                    {/* self-start stops the column layout stretching it to full
                        width on mobile; it sizes to its content instead. */}
                    <Button asChild className="shrink-0 self-start">
                        <Link href="/expenses/add">
                            <Plus className="mr-1 size-4" />
                            Record an expense
                        </Link>
                    </Button>
                </div>

                {expenses.data.length === 0 ? (
                    <div className="mt-6 flex flex-col items-center justify-center rounded-lg border border-dashed px-6 py-16 text-center">
                        <Receipt className="text-muted-foreground size-10" />
                        <h3 className="mt-4 font-medium">No expenses recorded yet</h3>
                        <p className="text-muted-foreground mt-1 max-w-sm text-sm">
                            Fuel, wages, rent, a padlock — anything the business spends on that is not stock to sell.
                        </p>
                        <Button asChild className="mt-6">
                            <Link href="/expenses/add">
                                <Plus className="mr-1 size-4" />
                                Record an expense
                            </Link>
                        </Button>
                    </div>
                ) : (
                    <>
                        <ul className="mt-6 grid items-start gap-2 sm:grid-cols-2 xl:grid-cols-3">
                            {expenses.data.map((expense) => (
                                <ExpenseCard key={expense.id} expense={expense} />
                            ))}
                        </ul>

                        <p className="text-muted-foreground mt-6 text-center text-sm">
                            Showing {expenses.from}–{expenses.to} of {expenses.total}
                        </p>

                        <PaginationNav links={expenses.links} className="mt-4" />
                    </>
                )}
            </div>
        </AppLayout>
    );
}
