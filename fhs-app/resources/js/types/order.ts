/**
 * A recorded sale, as shown in the orders list.
 *
 * Payment state is derived from what has been received, never stored — an order
 * can be complete but unpaid.
 */
export interface Order {
    id: number;
    occurred_at: string;
    customer: OrderCustomer;
    total_amount: number;
    paid_amount: number;
    due_amount: number;
    payment_state: 'paid' | 'partial' | 'due';
    items: OrderLine[];
}

export interface OrderCustomer {
    id: number;
    name: string;
    /** Null for a walk-in with no number on file. */
    mobile_number: string | null;
}

export interface OrderLine {
    id: number;
    display_name: string;
    transaction_type: string;
    transaction_label: string;
    /** Set only on a cross-brand swap: the empty handed back was another product. */
    returned_name: string | null;
    quantity: number;
    unit_price: number;
    line_total: number;
}

/** A product available to sell. */
export interface SellableItem {
    id: number;
    display_name: string;
    is_gas: boolean;
    is_returnable: boolean;
}

/** A kind of sale — swap, outright purchase, plain goods. */
export interface TransactionTypeOption {
    value: string;
    label: string;
    /** Whether the customer hands a shell back, which allows a cross-brand swap. */
    returns_shell: boolean;
}

/** An existing customer found by mobile number while filling in the form. */
export interface CustomerLookup {
    name: string;
    address: string | null;
    /** What they already owe across previous orders. */
    outstanding_balance: number;
}
