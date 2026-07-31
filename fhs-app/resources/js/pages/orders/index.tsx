import OrderCard from '@/components/orders/order-card';
import PaginationNav from '@/components/pagination-nav';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { type Order } from '@/types/order';
import { type Paginated } from '@/types/pagination';
import { Head, Link } from '@inertiajs/react';
import { Plus, ShoppingBag } from 'lucide-react';

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Orders', href: '/orders' }];

export default function OrdersIndex({ orders }: { orders: Paginated<Order> }) {
    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Orders" />

            <div className="px-4 py-6">
                <div className="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                    <div>
                        <h1 className="text-xl font-semibold">Orders</h1>
                        <p className="text-muted-foreground mt-1 text-sm">Customer sales, newest first</p>
                    </div>

                    {/* self-start stops the column layout stretching it to full
                        width on mobile; it sizes to its content instead. */}
                    <Button asChild className="shrink-0 self-start">
                        <Link href="/orders/add">
                            <Plus className="mr-1 size-4" />
                            Record a sale
                        </Link>
                    </Button>
                </div>

                {orders.data.length === 0 ? (
                    <div className="mt-6 flex flex-col items-center justify-center rounded-lg border border-dashed px-6 py-16 text-center">
                        <ShoppingBag className="text-muted-foreground size-10" />
                        <h3 className="mt-4 font-medium">No orders recorded yet</h3>
                        <p className="text-muted-foreground mt-1 max-w-sm text-sm">
                            Record what was sold and to whom — stock and customer balances follow from these.
                        </p>
                        <Button asChild className="mt-6">
                            <Link href="/orders/add">
                                <Plus className="mr-1 size-4" />
                                Record a sale
                            </Link>
                        </Button>
                    </div>
                ) : (
                    <>
                        {/* items-start: grid items stretch to the tallest in
                            their row by default, so opening one card's accordion
                            would grow the collapsed ones beside it. */}
                        <ul className="mt-6 grid items-start gap-2 sm:grid-cols-2 xl:grid-cols-3">
                            {orders.data.map((order) => (
                                <OrderCard key={order.id} order={order} />
                            ))}
                        </ul>

                        <p className="text-muted-foreground mt-6 text-center text-sm">
                            Showing {orders.from}–{orders.to} of {orders.total}
                        </p>

                        <PaginationNav links={orders.links} className="mt-4" />
                    </>
                )}
            </div>
        </AppLayout>
    );
}
