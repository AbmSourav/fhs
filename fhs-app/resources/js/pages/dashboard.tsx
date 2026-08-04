import DeltaBadge from '@/components/dashboard/delta-badge';
import StatCard from '@/components/dashboard/stat-card';
// TransactionMixChart is kept for the commented-out panel below.
// eslint-disable-next-line @typescript-eslint/no-unused-vars
import { DailyRevenueChart, NetProfitTrendChart, RevenueTrendChart, TransactionMixChart } from '@/components/dashboard/trend-charts';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { type MonthlyFigures, type Trends } from '@/types/dashboard';
import { Head } from '@inertiajs/react';
import { Banknote, Coins, Package, Receipt, ShoppingBag, TrendingUp, Wallet } from 'lucide-react';

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Dashboard', href: '/dashboard' }];

interface Props {
    month: MonthlyFigures;
    trends: Trends;
}

export default function Dashboard({ month, trends }: Props) {
    const against = month.previous_month_label;

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Dashboard" />

            <div className="px-4 py-6">
                <div>
                    <h1 className="text-xl font-semibold">{month.month_label}</h1>
                    <p className="text-muted-foreground mt-1 text-sm">This month so far, against {against}</p>
                </div>

                <div className="mt-4 grid grid-cols-2 gap-4 sm:grid-cols-3 xl:grid-cols-7">
                    <StatCard
                        label="Revenue"
                        value={month.revenue.current}
                        icon={ShoppingBag}
                        hint="Billed this month"
                        tone="positive"
                    >
                        <DeltaBadge metric={month.revenue} against={against} />
                    </StatCard>

                    <StatCard label="Sales" value={month.sales_count.current} icon={Receipt} hint="Orders recorded" format="number">
                        <DeltaBadge metric={month.sales_count} against={against} />
                    </StatCard>

                    <StatCard label="Gross profit" value={month.gross_profit.current} icon={TrendingUp} hint="Less what goods cost">
                        <DeltaBadge metric={month.gross_profit} against={against} />
                    </StatCard>

                    <StatCard label="Average sale" value={month.average_order.current} icon={Package} hint="Revenue per order">
                        <DeltaBadge metric={month.average_order} against={against} />
                    </StatCard>

                    {/* Keyed on when payment arrived, so this differs from
                        revenue whenever a customer settles an older sale. */}
                    <StatCard label="Money received" value={month.collected.current} icon={Wallet} hint="Cash received this month">
                        <DeltaBadge metric={month.collected} against={against} />
                    </StatCard>

                    <StatCard label="Expenses" value={month.expenses.current} icon={Banknote} hint="Running costs and transport">
                        {/* Spending less is the good direction here, unlike
                            every other card in this row. */}
                        <DeltaBadge metric={month.expenses} against={against} goodDirection="down" />
                    </StatCard>

                    {/* What the month actually made. Tinted by sign, since this
                        is the one figure here that can legitimately go
                        negative. */}
                    <StatCard
                        label="Net profit"
                        value={month.net_profit.current}
                        icon={Coins}
                        hint="After goods and expenses"
                        tone={month.net_profit.current < 0 ? 'negative' : 'positive'}
                    >
                        <DeltaBadge metric={month.net_profit} against={against} />
                    </StatCard>
                </div>

                <h2 className="text-muted-foreground mt-9 text-xs font-medium tracking-wide uppercase">Trends</h2>

                <div className="mt-3 grid gap-4 grid-cols-1 sm:grid-cols-2">
                    <RevenueTrendChart data={trends.monthly} />
                    {/* Next to revenue on purpose: the gap between the two is
                        what the business spent to earn it. */}
                    <NetProfitTrendChart data={trends.monthly} />
                    {/* <TransactionMixChart data={trends.monthly} /> */}
                    <DailyRevenueChart data={trends.daily} month={month.month_label} />
                </div>
            </div>
        </AppLayout>
    );
}
