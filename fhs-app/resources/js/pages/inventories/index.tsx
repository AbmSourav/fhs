import PurchaseCard from '@/components/inventories/purchase-card';
import PaginationNav from '@/components/pagination-nav';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem, type SharedData } from '@/types';
import { type InventoryPurchase } from '@/types/inventory';
import { type Paginated } from '@/types/pagination';
import { Head, Link, usePage } from '@inertiajs/react';
import { Boxes, Plus } from 'lucide-react';

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Inventory', href: '/inventories' }];

export default function InventoriesIndex({ purchases }: { purchases: Paginated<InventoryPurchase> }) {
    // A view-only account is refused the setup page server-side, so offering
    // the way in would only lead them to a 403.
    const { auth } = usePage<SharedData>().props;

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Inventory" />

            <div className="px-4 py-6">
                <div className="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                    <div>
                        <h1 className="text-xl font-semibold">Inventory</h1>
                        <p className="text-muted-foreground mt-1 text-sm">Stock purchases, newest first</p>
                    </div>

                    {/* self-start stops the column layout stretching it to full
                        width on mobile; it sizes to its content instead. */}
                    <Button asChild can={auth.canWrite} className="shrink-0 self-start">
                        <Link href="/inventories/add">
                            <Plus className="mr-1 size-4" />
                            Purchase
                        </Link>
                    </Button>
                </div>

                {purchases.data.length === 0 ? (
                    <div className="mt-6 flex flex-col items-center justify-center rounded-lg border border-dashed px-6 py-16 text-center">
                        <Boxes className="text-muted-foreground size-10" />
                        <h3 className="mt-4 font-medium">No purchases recorded yet</h3>
                        <p className="text-muted-foreground mt-1 max-w-sm text-sm">
                            Record what arrived from a supplier — stock levels are worked out from these.
                        </p>
                        <Button asChild can={auth.canWrite} className="mt-6">
                            <Link href="/inventories/add">
                                <Plus className="mr-1 size-4" />
                                Add purchase
                            </Link>
                        </Button>
                    </div>
                ) : (
                    <>
                        {/* items-start: grid items stretch to the tallest in
                            their row by default, so opening one card's accordion
                            would grow the collapsed ones beside it. */}
                        <ul className="mt-6 grid items-start gap-2 sm:grid-cols-2 xl:grid-cols-3">
                            {purchases.data.map((purchase) => (
                                <PurchaseCard key={purchase.key} purchase={purchase} />
                            ))}
                        </ul>

                        <p className="text-muted-foreground mt-6 text-center text-sm">
                            Showing {purchases.from}–{purchases.to} of {purchases.total}
                        </p>

                        <PaginationNav links={purchases.links} className="mt-4" />
                    </>
                )}
            </div>
        </AppLayout>
    );
}
