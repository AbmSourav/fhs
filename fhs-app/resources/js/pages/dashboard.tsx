import StatCard from '@/components/dashboard/stat-card';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { type AllTimePosition } from '@/types/dashboard';
import { Head } from '@inertiajs/react';
import { Boxes, Cylinder, Package, Receipt, ShoppingBag, TrendingUp, Wallet } from 'lucide-react';

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Dashboard', href: '/dashboard' }];

export default function Dashboard({ position }: { position: AllTimePosition }) {
    const inProfit = position.net_profit >= 0;

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Dashboard" />

            <div className="px-4 py-6">
                <div>
                    <h1 className="text-xl font-semibold">All time</h1>
                    <p className="text-muted-foreground mt-1 text-sm">Everything recorded since the business started</p>
                </div>

                <h2 className="text-muted-foreground mt-6 text-xs font-medium tracking-wide uppercase">Trading</h2>

                <div className="mt-3 grid grid-cols-2 gap-4 sm:grid-cols-3 xl:grid-cols-5">
                    <StatCard label="Revenue" value={position.revenue} icon={ShoppingBag} hint="Billed to customers" tone="positive" />

                    <StatCard label="Cost of goods sold" value={position.cogs} icon={Package} hint="What the goods sold cost" />

                    <StatCard label="Gross profit" value={position.gross_profit} icon={TrendingUp} hint="Revenue less goods cost" />

                    <StatCard label="Other expenses" value={position.other_expenses} icon={Receipt} hint="Transport, wages, rent" />

                    <StatCard
                        label="Net profit"
                        value={position.net_profit}
                        icon={Wallet}
                        hint={inProfit ? 'After all spending' : 'Spent more than earned'}
                        tone={inProfit ? 'positive' : 'negative'}
                    />
                </div>

                <h2 className="text-muted-foreground mt-9 text-xs font-medium tracking-wide uppercase">Current assets</h2>

                <div className="mt-3 grid grid-cols-2 gap-4 sm:grid-cols-3">
                    <StatCard label="Stock on hand" value={position.stock_value} icon={Boxes} hint="Bought, not yet sold" />

                    <StatCard label="Cylinders owned" value={position.shell_value} icon={Cylinder} hint="Including those out with customers" />

                    <StatCard label="Total assets" value={position.current_assets} icon={Wallet} hint="Money held as goods" />
                </div>

                {/* <p className="text-muted-foreground mt-6 max-w-2xl text-xs">
                    Cost is counted when goods sell, not when they are bought — so a delivery raises stock on hand rather than lowering
                    profit. Cylinders stay an asset even while a customer holds one, because a swap returns the empty and the business
                    keeps the steel.
                </p> */}
            </div>
        </AppLayout>
    );
}
