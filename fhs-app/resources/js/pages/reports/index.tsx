import ItemSalesCards from '@/components/reports/item-sales-cards';
import ReportHeader from '@/components/reports/report-header';
import Row from '@/components/reports/report-row';
import SectionTitle from '@/components/reports/section-title';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { type MonthlyReport, type ReportMonth } from '@/types/dashboard';
import { Head, router } from '@inertiajs/react';
import { CalendarRange, Download } from 'lucide-react';

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Reports', href: '/reports' }];

interface Props {
    /** Null until a month is picked — the page opens with nothing reported. */
    report: MonthlyReport | null;
    months: ReportMonth[];
}

export default function ReportsIndex({ report, months }: Props) {
    // The month rides in the query string so a report can be linked to or
    // bookmarked, and the browser's back button moves between months.
    const showMonth = (month: string) => {
        router.get('/reports', { month }, { preserveState: true, preserveScroll: true, replace: true });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={report ? `Report — ${report.month_label}` : 'Reports'} />

            <div className="px-3 sm:px-8 py-10">
                <div data-print="hide">
                    <h1 className="text-xl font-semibold">Reports</h1>
                    <p className="text-muted-foreground mt-1 text-sm">A month's trading. Download the PDF for a copy to keep or send on.</p>
                </div>

                <div data-print="hide" className="mt-6 flex flex-col gap-3 sm:flex-row sm:items-end">
                    <div className="grid gap-1">
                        <Label htmlFor="month" className="text-xs">
                            Month
                        </Label>
                        {/* Undefined rather than an empty string when nothing is
                            chosen: a Radix select shows its placeholder only
                            when it has no value at all. */}
                        <Select value={report?.month ?? undefined} onValueChange={showMonth}>
                            <SelectTrigger id="month" className="h-9 w-full sm:w-56">
                                <SelectValue placeholder="Select month" />
                            </SelectTrigger>
                            <SelectContent>
                                {months.map((month) => (
                                    <SelectItem key={month.value} value={month.value}>
                                        {month.label}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </div>

                    {/* Nothing to download until there is a report to download. */}
                    {report && (
                        <Button asChild className="h-9 gap-2 sm:self-end">
                            <a href={`/reports/download?month=${report.month}`}>
                                <Download className="size-4" />
                                Download PDF
                            </a>
                        </Button>
                    )}
                </div>

                {report ? (
                    <ReportBody report={report} />
                ) : (
                    /* The page opens here. A report shown without being asked
                       for reads as the current position, and whichever month it
                       happened to pick is easy to miss. */
                    <div
                        data-print="hide"
                        className="mt-8 flex max-w-3xl flex-col items-center justify-center rounded-lg border border-dashed px-6 py-16 text-center"
                    >
                        <CalendarRange className="text-muted-foreground size-10" />
                        <h2 className="mt-4 font-medium">Choose a month</h2>
                        <p className="text-muted-foreground mt-1 max-w-sm text-sm">
                            Pick a month above to see what it traded, then download the PDF if you need a copy.
                        </p>
                    </div>
                )}
            </div>
        </AppLayout>
    );
}

/** The report itself, once a month has been chosen. */
function ReportBody({ report }: { report: MonthlyReport }) {
    return (
        <div className="mt-8 max-w-3xl px-2">
            <ReportHeader report={report} />

            <section data-print="block" className="mt-8">
                <SectionTitle>Trading</SectionTitle>

                <Row label="Revenue" hint="Everything billed to customers" value={report.revenue} />
                <Row label="Sales" hint="Orders recorded" value={report.sales_count} format="number" />
                <Row label="Average sale" hint="Revenue per order" value={report.average_order} />
            </section>

            <section data-print="block" className="mt-8">
                <SectionTitle>Profit</SectionTitle>

                <Row label="Revenue" value={report.revenue} />
                <Row label="Cost of goods sold" hint="What the goods sold cost" value={-report.cogs} />
                <Row label="Gross profit" value={report.gross_profit} emphasis />
                <Row label="Expenses" hint="Running costs and consignment transport" value={-report.expenses} />
                <Row label="Net profit" value={report.net_profit} emphasis total />
            </section>

            {report.revenue != report.collected && (
                <section data-print="block" className="mt-8">
                    <SectionTitle>Cash</SectionTitle>

                    {/* Differs from revenue whenever a customer settles an older
                        sale, so it is stated separately rather than folded into
                        the profit figures. */}
                    <Row label="Money received" hint="Collected this month, whichever month the sale was in" value={report.collected} />
                </section>
            )}

            {/* Nothing sold means nothing to break down, and an empty list says
                less than no list at all. */}
            {report.items.length > 0 && (
                <section className="mt-7 pt-5 border-t-2">
                    <SectionTitle>Sales by item</SectionTitle>

                    <div className="mt-3">
                        <ItemSalesCards items={report.items} />
                    </div>
                </section>
            )}
        </div>
    );
}
