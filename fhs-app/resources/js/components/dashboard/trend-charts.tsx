import { type ChartConfig, ChartContainer, ChartTooltip, ChartTooltipContent } from '@/components/ui/chart';
import { type DailyPoint, type MonthlyPoint } from '@/types/dashboard';
import { Bar, BarChart, CartesianGrid, Cell, Line, LineChart, ReferenceLine, XAxis, YAxis } from 'recharts';

// narrowSymbol gives the ৳ sign; the default for BDT is the "BDT" code.
const currency = new Intl.NumberFormat('en-BD', {
    style: 'currency',
    currency: 'BDT',
    currencyDisplay: 'narrowSymbol',
    maximumFractionDigits: 0,
});

/**
 * Axis labels have little room, so figures are abbreviated.
 *
 * en-BD groups in lakh and crore rather than thousands, which is why this is
 * spelled out rather than dividing by 1,000.
 */
function shortCurrency(value: number): string {
    if (Math.abs(value) >= 10_000_000) {
        return `৳${(value / 10_000_000).toFixed(1)}Cr`;
    }

    if (Math.abs(value) >= 100_000) {
        return `৳${(value / 100_000).toFixed(1)}L`;
    }

    if (Math.abs(value) >= 1_000) {
        return `৳${Math.round(value / 1_000)}k`;
    }

    return `৳${value}`;
}

/** Wraps a chart in a titled panel, so the four read as a set. */
function Panel({ title, subtitle, children }: { title: string; subtitle: string; children: React.ReactNode }) {
    return (
        <div className="rounded-xl border p-4">
            <p className="text-sm font-medium">{title}</p>
            <p className="text-muted-foreground mt-0.5 text-xs">{subtitle}</p>
            {children}
        </div>
    );
}

const revenueConfig = {
    revenue: { label: 'Revenue', color: 'var(--chart-1)' },
} satisfies ChartConfig;

/** Revenue by month, over the last year. */
export function RevenueTrendChart({ data }: { data: MonthlyPoint[] }) {
    return (
        <Panel title="Revenue by month" subtitle="Last 12 months">
            {/* An explicit height: a Recharts chart in a grid child with no
                height collapses to nothing and renders blank. */}
            <ChartContainer config={revenueConfig} className="mt-4 h-[220px] w-full">
                <BarChart data={data} accessibilityLayer>
                    <CartesianGrid vertical={false} />
                    <XAxis dataKey="label" tickLine={false} axisLine={false} tickMargin={8} />
                    <YAxis tickLine={false} axisLine={false} width={52} tickFormatter={shortCurrency} />
                    <ChartTooltip content={<ChartTooltipContent formatter={(value) => currency.format(Number(value))} />} />
                    <Bar dataKey="revenue" fill="var(--color-revenue)" radius={4} />
                </BarChart>
            </ChartContainer>
        </Panel>
    );
}

const cashConfig = {
    revenue: { label: 'Billed', color: 'var(--chart-1)' },
    collected: { label: 'Collected', color: 'var(--chart-2)' },
} satisfies ChartConfig;

/** What was billed against what was actually received. */
export function RevenueVsCollectedChart({ data }: { data: MonthlyPoint[] }) {
    return (
        <Panel title="Billed vs collected" subtitle="A gap means money still owed">
            <ChartContainer config={cashConfig} className="mt-4 h-[220px] w-full">
                <LineChart data={data} accessibilityLayer>
                    <CartesianGrid vertical={false} />
                    <XAxis dataKey="label" tickLine={false} axisLine={false} tickMargin={8} />
                    <YAxis tickLine={false} axisLine={false} width={52} tickFormatter={shortCurrency} />
                    <ChartTooltip content={<ChartTooltipContent formatter={(value) => currency.format(Number(value))} />} />
                    <Line dataKey="revenue" stroke="var(--color-revenue)" strokeWidth={2} dot={false} />
                    <Line dataKey="collected" stroke="var(--color-collected)" strokeWidth={2} dot={false} />
                </LineChart>
            </ChartContainer>
        </Panel>
    );
}

const netProfitConfig = {
    net_profit: { label: 'Net profit', color: 'var(--chart-2)' },
} satisfies ChartConfig;

/**
 * What each month actually made, after cost of goods and expenses.
 *
 * Distinct from revenue: a busy month that bought heavily or paid a big bill
 * can still lose money, and only this chart shows that.
 */
export function NetProfitTrendChart({ data }: { data: MonthlyPoint[] }) {
    return (
        <Panel title="Net profit by month" subtitle="After cost of goods and expenses">
            <ChartContainer config={netProfitConfig} className="mt-4 h-[220px] w-full">
                <BarChart data={data} accessibilityLayer>
                    <CartesianGrid vertical={false} />
                    <XAxis dataKey="label" tickLine={false} axisLine={false} tickMargin={8} />
                    <YAxis tickLine={false} axisLine={false} width={52} tickFormatter={shortCurrency} />
                    <ChartTooltip content={<ChartTooltipContent formatter={(value) => currency.format(Number(value))} />} />
                    {/* Without this the axis floats and a losing month is not
                        obviously below zero. */}
                    <ReferenceLine y={0} stroke="var(--border)" />
                    <Bar dataKey="net_profit" radius={4}>
                        {/* Coloured per bar: a loss is red, not a shorter green
                            bar, so the sign reads at a glance. */}
                        {data.map((point) => (
                            <Cell
                                key={point.label}
                                fill={point.net_profit < 0 ? 'var(--destructive)' : 'var(--color-net_profit)'}
                            />
                        ))}
                    </Bar>
                </BarChart>
            </ChartContainer>
        </Panel>
    );
}

const mixConfig = {
    swap: { label: 'Swap', color: 'var(--chart-1)' },
    outright: { label: 'Outright', color: 'var(--chart-4)' },
} satisfies ChartConfig;

/**
 * Swap against outright sales, by month.
 *
 * A rising outright share means cylinders are leaving the business rather than
 * circulating, which drains the shells available to refill.
 */
export function TransactionMixChart({ data }: { data: MonthlyPoint[] }) {
    return (
        <Panel title="Swap vs outright" subtitle="Cylinders returned, or kept by the customer">
            <ChartContainer config={mixConfig} className="mt-4 h-[220px] w-full">
                <BarChart data={data} accessibilityLayer>
                    <CartesianGrid vertical={false} />
                    <XAxis dataKey="label" tickLine={false} axisLine={false} tickMargin={8} />
                    <YAxis tickLine={false} axisLine={false} width={32} allowDecimals={false} />
                    <ChartTooltip content={<ChartTooltipContent />} />
                    <Bar dataKey="swap" stackId="mix" fill="var(--color-swap)" radius={[0, 0, 4, 4]} />
                    <Bar dataKey="outright" stackId="mix" fill="var(--color-outright)" radius={[4, 4, 0, 0]} />
                </BarChart>
            </ChartContainer>
        </Panel>
    );
}

/** Revenue day by day through the month in progress. */
export function DailyRevenueChart({ data, month }: { data: DailyPoint[]; month: string }) {
    return (
        <Panel title="Revenue by day" subtitle={month}>
            <ChartContainer config={revenueConfig} className="mt-4 h-[220px] w-full">
                <BarChart data={data} accessibilityLayer>
                    <CartesianGrid vertical={false} />
                    <XAxis dataKey="label" tickLine={false} axisLine={false} tickMargin={8} interval={2} />
                    <YAxis tickLine={false} axisLine={false} width={52} tickFormatter={shortCurrency} />
                    <ChartTooltip content={<ChartTooltipContent formatter={(value) => currency.format(Number(value))} />} />
                    <Bar dataKey="revenue" fill="var(--color-revenue)" radius={2} />
                </BarChart>
            </ChartContainer>
        </Panel>
    );
}
