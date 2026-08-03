import CrmCustomerCard from '@/components/crm/crm-customer-card';
import PaginationNav from '@/components/pagination-nav';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
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
    repeat: 'Who come back — worth keeping close',
    follow_up: 'Promised a call back — soonest first, overdue in red',
};

interface Props {
    customers: Paginated<CrmCustomer>;
    active: CrmFilters;
    options: CrmOptions;
}

export default function CrmIndex({ customers, active, options }: Props) {
    const isRepeat = active.filter === 'repeat';

    // The follow-up list counts forward — callbacks promised between now and N
    // days out — where the other day-based lists count backward.
    const isFollowUp = active.filter === 'follow_up';

    // The threshold each list falls back to when nothing is typed.
    const fallback = isRepeat
        ? options.default_repeat_minimum
        : isFollowUp
          ? options.default_follow_up_days
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

                {/* Two halves of one question — which list, and how it is
                    tuned — so they sit together rather than at opposite ends. */}
                <div className="mt-8 flex flex-col gap-3 sm:flex-row sm:items-end">
                    <div className="grid gap-1">
                        <Label htmlFor="filter" className="text-xs">
                            Customer types
                        </Label>
                        {/* Switching list clears the threshold, so the new one
                            starts on its own default rather than inheriting a
                            number that meant something else. */}
                        <Select value={active.filter} onValueChange={(value) => load(value, '')}>
                            <SelectTrigger id="filter" className="h-9 w-full sm:w-56">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                {Object.entries(options.filters).map(([key, label]) => (
                                    <SelectItem key={key} value={key}>
                                        {label}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </div>

                    <div className="grid gap-1">
                        <Label htmlFor="threshold" className="text-xs flex w-46">
                            {isRepeat
                                ? 'At least this many orders'
                                : isFollowUp
                                  ? 'Follow-up within this many days'
                                  : 'Days since their last order'}
                        </Label>
                        <Input
                            id="threshold"
                            type="text"
                            min={1}
                            className="h-9 w-40 sm:w-44"
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
                        <p className="text-green-900 dark:text-green-500 mt-8 text-md font-bold">
                            {customers.total} {customers.total === 1 ? 'customer' : 'customers'}
                        </p>
                        <p className="mt-1 text-xs text-green-800 dark:text-green-600">{description[active.filter]}</p>

                        <ul className="mt-3 grid items-start gap-3 sm:grid-cols-2 xl:grid-cols-3">
                            {customers.data.map((customer) => (
                                <CrmCustomerCard key={customer.id} customer={customer} />
                            ))}
                        </ul>

                        <PaginationNav links={customers.links} className="mt-6" />
                    </>
                )}
            </div>
        </AppLayout>
    );
}
