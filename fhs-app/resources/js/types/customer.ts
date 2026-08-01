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

/** A customer with their full trading history. */
export interface CustomerProfile extends Customer {
    /** How long without an order counts as lapsed, for explaining the badge. */
    lapsed_after_days: number;
    timeline: TimelineEntry[];
}

/**
 * One moment in a customer's history.
 *
 * A sale and a payment collected later are separate events, so an order left
 * due and settled a week on appears twice.
 */
export type TimelineEntry = TimelineSale | TimelinePayment;

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
