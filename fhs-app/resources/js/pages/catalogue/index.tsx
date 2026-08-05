import CatalogueItemCard from '@/components/catalogue/catalogue-item-card';
import Heading from '@/components/heading';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem, type SharedData } from '@/types';
import { type CatalogueItem } from '@/types/catalogue';
import { Head, Link, usePage } from '@inertiajs/react';
import { Package, Plus } from 'lucide-react';

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Catalogue', href: '/catalogue' }];

export default function CatalogueIndex({ items }: { items: CatalogueItem[] }) {
    // A view-only account is refused the setup page server-side, so offering
    // the way in would only lead them to a 403.
    const { auth } = usePage<SharedData>().props;

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Catalogue" />

            <div className="px-4 py-6">
                <div className="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                    <Heading title="Catalogue" description="Products the business sells, and current stock for each" />

                    {/* self-start stops the column layout stretching it to full
                        width on mobile; it sizes to its content instead. */}
                    <Button asChild can={auth.canWrite} className="shrink-0 self-start">
                        <Link href="/catalogue/setup">
                            <Plus className="mr-1 size-4" />
                            Setup
                        </Link>
                    </Button>
                </div>

                {items.length === 0 ? (
                    <div className="mt-6 flex flex-col items-center justify-center rounded-lg border border-dashed px-6 py-16 text-center">
                        <Package className="text-muted-foreground size-10" />
                        <h3 className="mt-4 font-medium">No catalogue items yet</h3>
                        <p className="text-muted-foreground mt-1 max-w-sm text-sm">
                            Add the products you sell — each brand and weight is tracked separately.
                        </p>
                        <Button asChild className="mt-6">
                            <Link href="/catalogue/setup">
                                <Plus className="mr-1 size-4" />
                                Setup catalogue
                            </Link>
                        </Button>
                    </div>
                ) : (
                    <ul className="mt-6 grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                        {items.map((item) => (
                            <CatalogueItemCard key={item.id} item={item} />
                        ))}
                    </ul>
                )}
            </div>
        </AppLayout>
    );
}
