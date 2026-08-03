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
