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
