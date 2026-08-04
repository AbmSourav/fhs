import { type MonthlyReport } from '@/types/dashboard';

/** The masthead — what this document is, and what period it covers. */
export default function ReportHeader({ report }: { report: MonthlyReport }) {
    return (
        <div className="flex items-start justify-between gap-4 border-b pb-4">
            <div>
                <p className="text-lg font-semibold">Fast Home Service</p>
                <p className="text-muted-foreground mt-0.5 text-sm">{report.month_label} report</p>
            </div>
        </div>
    );
}
