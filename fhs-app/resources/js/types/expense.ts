/**
 * Money spent running the business that is not stock.
 *
 * Separate from a purchase because it never becomes something to sell, and it
 * touches neither stock nor any customer balance.
 */
export interface Expense {
    id: number;
    category: string;
    category_label: string;
    description: string;
    amount: number;
    /** Null when nobody was named. */
    paid_to: string | null;
    payment_method: string;
    method_label: string;
    receipt_ref: string | null;
    spent_at: string;
    /** False once the correction window has closed. Deleting stays available. */
    is_editable: boolean;
}

/**
 * An existing expense in the shape the add/edit form uses.
 *
 * `amount` is a string because it populates a text input; the server casts it
 * back on submit.
 */
export interface ExpenseFormValues {
    id: number;
    category: string;
    description: string;
    amount: string;
    paid_to: string;
    payment_method: string;
    receipt_ref: string;
    spent_at: string;
}

/** A choice in one of the form's selects. */
export interface ExpenseOption {
    value: string;
    label: string;
}
