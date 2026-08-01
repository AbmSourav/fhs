/**
 * A recorded sale, as shown in the orders list.
 *
 * Payment state is derived from what has been received, never stored — an order
 * can be complete but unpaid.
 */
export interface Order {
    id: number;
    occurred_at: string;
    /** False once a fully paid sale has passed its correction window. */
    is_editable: boolean;
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
    /** Set only when the shell and gas were priced separately. */
    cylinder_price: number | null;
    gas_price: number | null;
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
    /** Whether the customer keeps the shell, so it is priced apart from the gas. */
    sells_shell: boolean;
}

/**
 * An existing sale in the shape the add/edit form uses.
 *
 * Amounts are strings because they populate text inputs; the server casts them
 * back on submit.
 */
export interface OrderFormValues {
    id: number;
    mobile_number: string;
    customer_name: string;
    address: string;
    occurred_at: string;
    items: OrderFormLine[];
    is_paid: boolean;
    amount_paid: string;
    payment_method: string;
}

export interface OrderFormLine {
    catalogue_id: string;
    transaction_type: string;
    /** Empty unless the customer handed back a different brand. */
    returned_catalogue_id: string;
    quantity: string;
    unit_price: string;
}

/** A sale with what it still owes, for the payment form. */
export interface OrderPayment {
    id: number;
    customer: Pick<OrderCustomer, 'name' | 'mobile_number'>;
    occurred_at: string;
    total_amount: number;
    paid_amount: number;
    /** What is left to settle. Prefills the amount field. */
    due_amount: number;
    /** What has been received so far, so staff can see the instalments. */
    payments: ReceivedPayment[];
}

export interface ReceivedPayment {
    id: number;
    amount: number;
    method: string;
    paid_at: string;
}

/** An existing customer found by mobile number while filling in the form. */
export interface CustomerLookup {
    name: string;
    address: string | null;
    /** What they already owe across previous orders. */
    outstanding_balance: number;
}
