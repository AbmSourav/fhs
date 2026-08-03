/**
 * A customer with their trading history.
 *
 * Every figure is derived from orders and payments rather than stored, so a
 * corrected order changes these immediately.
 */
export interface Customer {
    id: number;
    name: string;
    /** Null for a walk-in who gave no number. */
    mobile_number: string | null;
    address: string | null;
    /** Orders that actually happened — failed ones count toward nothing. */
    order_count: number;
    total_spent: number;
    /** Still owed across every order. */
    due_amount: number;
    /** Null for a customer on file who has never ordered. */
    last_ordered_at: string | null;
    /** Overdue for a repeat purchase — not necessarily gone for good. */
    has_lapsed: boolean;
}

/**
 * A customer on a follow-up list.
 *
 * The same figures as the customer book, plus how long it has been — which is
 * the reason they are on the list at all.
 */
export interface CrmCustomer extends Customer {
    /** Null only for a customer with no orders, who never appears here. */
    days_since_order: number | null;
    /** Null until somebody calls them. */
    last_called_at: string | null;
    /** A callback staff promised and have not honoured yet, if any. */
    next_callback_on: string | null;
}

/** One recorded call, as shown on the follow-up form. */
export interface FollowUp {
    id: number;
    outcome: string;
    note: string;
    /** When the call was placed. Stored UTC, edited in business time. */
    called_at: string;
    customer: {
        id: number;
        name: string;
        mobile_number: string | null;
        address: string | null;
    };
    /** Earlier calls, so staff can see what was said last time. */
    history: PastCall[];
}

/** A choice in the outcome select. */
export interface OutcomeOption {
    value: string;
    label: string;
}

export interface PastCall {
    id: number;
    outcome_label: string;
    note: string | null;
    called_at: string;
    /** Null if the user who made the call has since been removed. */
    called_by: string | null;
}

/** Which call list is showing, and how it is tuned. */
export interface CrmFilters {
    filter: string;
    /** Null means the list's own default applies. */
    days: number | null;
    min_orders: number | null;
}

/** The choices behind the filter controls. */
export interface CrmOptions {
    filters: Record<string, string>;
    default_due_days: number;
    default_lapsed_days: number;
    default_repeat_minimum: number;
    /** How far ahead the follow-up list looks, in days. */
    default_follow_up_days: number;
}

/** A customer with their full trading history. */
export interface CustomerProfile extends Customer {
    /** How long without an order counts as lapsed, for explaining the badge. */
    lapsed_after_days: number;
    timeline: TimelineEntry[];
}

/**
 * One moment in a customer's history.
 *
 * A sale, a payment collected later, and a follow-up call are separate events,
 * so an order left due and settled a week on appears twice.
 */
export type TimelineEntry = TimelineSale | TimelinePayment | TimelineCall;

/**
 * A sale, with what it left owing at the time.
 *
 * The amounts describe the moment of the sale, not today: money collected on a
 * later visit belongs to its own entry, not this one.
 */
export interface TimelineSale {
    kind: 'sale';
    id: number;
    occurred_at: string;
    total_amount: number;
    /** Taken at delivery. Excludes anything collected later. */
    paid_amount: number;
    /** What the customer walked away owing. */
    due_amount: number;
    /** How the sale stood at the time — not its state now. */
    payment_state: 'paid' | 'partial' | 'due';
    /** Whether that balance has since been collected. */
    settled_later: boolean;
    items: TimelineItem[];
}

/** Money collected on a later visit, against an earlier sale. */
export interface TimelinePayment {
    kind: 'payment';
    id: number;
    occurred_at: string;
    amount: number;
    method_label: string;
    /** What was still owed on that sale once this payment landed. */
    due_amount: number;
    order_id: number;
    /** The sale being settled, so the entry can point back at it. */
    order_occurred_at: string;
}

/**
 * A follow-up call, shown against the sales it sits between.
 *
 * Whether the customer bought after being chased is the question the call list
 * exists to answer, so the call belongs in the same stream as the orders.
 */
export interface TimelineCall {
    kind: 'call';
    id: number;
    occurred_at: string;
    outcome: string;
    outcome_label: string;
    /** False for an unanswered call, which left the customer as they were. */
    conclusive: boolean;
    note: string | null;
    /** A promised callback date, if one was agreed. */
    call_again_on: string | null;
    /** Null if the user who made the call has since been removed. */
    called_by: string | null;
}

export interface TimelineItem {
    id: number;
    display_name: string;
    transaction_label: string;
    quantity: number;
    line_total: number;
    /** Set only on a cross-brand swap. */
    returned_name: string | null;
}

/**
 * A customer in the shape the edit form uses.
 *
 * Only identity and contact details: everything else on the card is derived
 * from orders and payments, so there is nothing to edit it with.
 */
export interface CustomerFormValues {
    id: number;
    name: string;
    /** Empty string rather than null, since it populates a text input. */
    mobile_number: string;
    address: string;
}
