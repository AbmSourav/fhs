import CustomerCard from '@/components/customers/customer-card';
import PaginationNav from '@/components/pagination-nav';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { type Customer } from '@/types/customer';
import { type Paginated } from '@/types/pagination';
import { Head } from '@inertiajs/react';
import { Users } from 'lucide-react';

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Customers', href: '/customers' }];

export default function CustomersIndex({ customers }: { customers: Paginated<Customer> }) {
    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Customers" />

            <div className="px-4 py-6">
                <div>
                    <h1 className="text-xl font-semibold">Customers</h1>
                    <p className="text-muted-foreground mt-1 text-sm">Most frequent buyers first</p>
                </div>

                {customers.data.length === 0 ? (
                    <div className="mt-6 flex flex-col items-center justify-center rounded-lg border border-dashed px-6 py-16 text-center">
                        <Users className="text-muted-foreground size-10" />
                        <h3 className="mt-4 font-medium">No customers yet</h3>
                        <p className="text-muted-foreground mt-1 max-w-sm text-sm">
                            Customers are added automatically when a sale is recorded against their mobile number.
                        </p>
                    </div>
                ) : (
                    <>
                        <ul className="mt-6 grid items-start gap-2 sm:grid-cols-2 xl:grid-cols-3">
                            {customers.data.map((customer) => (
                                <CustomerCard key={customer.id} customer={customer} />
                            ))}
                        </ul>

                        <p className="text-muted-foreground mt-6 text-center text-sm">
                            Showing {customers.from}–{customers.to} of {customers.total}
                        </p>

                        <PaginationNav links={customers.links} className="mt-4" />
                    </>
                )}
            </div>
        </AppLayout>
    );
}
