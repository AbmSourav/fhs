/**
 * Life-of-the-business totals.
 *
 * No deltas: these are balances covering everything ever recorded, not a
 * month's flow, so there is no previous period to compare against.
 */
export interface AllTimePosition {
    /** Everything ever billed to customers. */
    revenue: number;
    /** What the goods sold actually cost, frozen at the moment of each sale. */
    cogs: number;
    /** Non-stock spending — fuel, wages, rent. */
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
