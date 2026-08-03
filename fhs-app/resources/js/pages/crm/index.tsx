import CustomerCard from '@/components/customers/customer-card';
import PaginationNav from '@/components/pagination-nav';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { type CrmCustomer, type CrmFilters, type CrmOptions } from '@/types/customer';
import { type Paginated } from '@/types/pagination';
import { Head, router } from '@inertiajs/react';
import { PhoneCall } from 'lucide-react';
import { useEffect, useState } from 'react';

const breadcrumbs: BreadcrumbItem[] = [{ title: 'CRM', href: '/crm' }];

/** What each list is for, so the page explains itself. */
const description: Record<string, string> = {
    due: 'Bought a while ago and likely ready for another cylinder',
    lapsed: 'Gone quiet for long enough to be worth chasing',
    repeat: 'Customers who come back — worth keeping close',
};

interface Props {
    customers: Paginated<CrmCustomer>;
    active: CrmFilters;
    options: CrmOptions;
}

export default function CrmIndex({ customers, active, options }: Props) {
    const isRepeat = active.filter === 'repeat';

    // The threshold each list falls back to when nothing is typed.
    const fallback = isRepeat
        ? options.default_repeat_minimum
        : active.filter === 'lapsed'
          ? options.default_lapsed_days
          : options.default_due_days;

    const [threshold, setThreshold] = useState(String(active.days ?? active.min_orders ?? fallback));

    // The input resets when the list changes, since the number means a
    // different thing on each — days for two of them, orders for the third.
    useEffect(() => {
        setThreshold(String(active.days ?? active.min_orders ?? fallback));
    }, [active.filter, active.days, active.min_orders, fallback]);

    const load = (filter: string, value: string) => {
        const parsed = Number(value);

        router.get(
            '/crm',
            {
                filter,
                // Omitted when blank or nonsense, so the server applies the
                // list's own default rather than rejecting the request.
                ...(parsed > 0 ? (filter === 'repeat' ? { min_orders: parsed } : { days: parsed }) : {}),
            },
            { preserveState: true, preserveScroll: true, replace: true },
        );
    };

    // Typed digits settle before a request goes out, so a three-digit number is
    // one reload rather than three.
    useEffect(() => {
        if (threshold === String(active.days ?? active.min_orders ?? fallback)) {
            return;
        }

        const timer = setTimeout(() => load(active.filter, threshold), 400);

        return () => clearTimeout(timer);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [threshold]);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="CRM" />

            <div className="px-4 py-6">
                <div>
                    <h1 className="text-xl font-semibold">CRM</h1>
                    <p className="text-muted-foreground mt-1 text-sm">Customer Relationship Management - Contact with potential customers</p>
                </div>

                <div className="mt-8 flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
                    <div className="flex flex-wrap gap-2">
                        {Object.entries(options.filters).map(([key, label]) => (
                            <Button
                                key={key}
                                size="sm"
                                variant={active.filter === key ? 'default' : 'outline'}
                                onClick={() => load(key, '')}
                            >
                                {label}
                            </Button>
                        ))}
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="threshold" className="text-xs">
                            {isRepeat ? 'At least this many orders' : 'Days since their last order'}
                        </Label>
                        <Input
                            id="threshold"
                            type="number"
                            min={1}
                            className="h-9 w-full sm:w-44"
                            value={threshold}
                            onChange={(e) => setThreshold(e.target.value)}
                        />
                    </div>
                </div>

                {customers.data.length === 0 ? (
                    <div className="mt-6 flex flex-col items-center justify-center rounded-lg border border-dashed px-6 py-16 text-center">
                        <PhoneCall className="text-muted-foreground size-10" />
                        <h3 className="mt-4 font-medium">Nobody to call</h3>
                        <p className="text-muted-foreground mt-1 max-w-sm text-sm">
                            No customer matches this yet. Try a different number, or another list.
                        </p>
                    </div>
                ) : (
                    <>
                        <p className="text-green-800 dark:text-green-600 mt-6 text-sm font-bold">
                            {customers.total} {customers.total === 1 ? 'customer' : 'customers'}
                        </p>
                        <p className="mt-1 text-xs text-green-800 dark:text-green-600">{description[active.filter]}</p>

                        <ul className="mt-3 grid items-start gap-3 sm:grid-cols-2 xl:grid-cols-3">
                            {customers.data.map((customer) => (
                                <CustomerCard key={customer.id} customer={customer} />
                            ))}
                        </ul>

                        <PaginationNav links={customers.links} className="mt-6" />
                    </>
                )}
            </div>
        </AppLayout>
    );
}
