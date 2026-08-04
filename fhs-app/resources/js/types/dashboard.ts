/** Which way a figure moved since the month before. */
export type DeltaDirection = 'up' | 'down' | 'flat' | 'new';

/** One figure, with how it compares to last month. */
export interface Metric {
    current: number;
    previous: number;
    delta: number;
    /** Null when last month was zero — a percentage against zero is undefined. */
    percent: number | null;
    direction: DeltaDirection;
}

/** This calendar month's trading, against the month before it. */
export interface MonthlyFigures {
    month_label: string;
    previous_month_label: string;
    revenue: Metric;
    sales_count: Metric;
    gross_profit: Metric;
    average_order: Metric;
    /** Money received this month, whichever month the sale was in. */
    collected: Metric;
    /** Non-stock spending: the expenses table plus consignment transport. */
    expenses: Metric;
    /** Gross profit less expenses. Negative in a month that overspent. */
    net_profit: Metric;
}

/**
 * One month on a trend chart.
 *
 * Every bucket is present even when nothing happened — a quiet month is a real
 * data point, and dropping it would silently shorten the axis.
 */
export interface MonthlyPoint {
    label: string;
    revenue: number;
    /** Cash received that month, whichever month the sale was in. */
    collected: number;
    /** Units sold where the cylinder came back. */
    swap: number;
    /** Units where the customer kept it. */
    outright: number;
    /** Non-stock spending that month. */
    expenses: number;
    /** Revenue less cost of goods sold less expenses. Negative on a loss. */
    net_profit: number;
}

/** One day of the month in progress. */
export interface DailyPoint {
    label: string;
    revenue: number;
}

export interface Trends {
    monthly: MonthlyPoint[];
    daily: DailyPoint[];
}

/**
 * One calendar month's trading, as a report.
 *
 * No deltas: a report states what a period was. How it compared with the month
 * before is the dashboard's question, not this one.
 */
export interface MonthlyReport {
    /** "2026-08" — what the picker round-trips. */
    month: string;
    month_label: string;
    revenue: number;
    sales_count: number;
    /** What the goods sold cost, frozen at the moment of each sale. */
    cogs: number;
    average_order: number;
    gross_profit: number;
    /** The expenses table plus consignment transport. */
    expenses: number;
    /** Gross profit less expenses. Negative on a loss. */
    net_profit: number;
    /** Cash received in the month, whichever month the sale was in. */
    collected: number;
    /** When the report was produced — figures are derived, so this matters. */
    generated_at: string;
}

/** A month offered by the report picker. */
export interface ReportMonth {
    /** "2026-08". */
    value: string;
    /** "August 2026". */
    label: string;
}

/**
 * Life-of-the-business totals.
 *
 * No deltas: these are balances covering everything ever recorded, not a
 * month's flow, so there is no previous period to compare against.
 */
export interface AllTimePosition {
    /** Everything ever billed to customers. */
    revenue: number;
    /** Sales recorded, excluding failed ones. A count, not money. */
    sales_count: number;
    /** What the goods sold actually cost, frozen at the moment of each sale. */
    cogs: number;
    /** Non-stock spending: the expenses table plus consignment transport. */
    other_expenses: number;
    /** Revenue less what the goods cost. */
    gross_profit: number;
    /** Gross profit less everything else spent. Negative when overspent. */
    net_profit: number;
    /** Gas and goods bought but not yet sold, at cost. */
    stock_value: number;
    /** What the cylinders the business owns cost. A shell is a durable asset. */
    shell_value: number;
    /** Stock plus shells — money held as goods rather than spent. */
    current_assets: number;
}
