/**
 * A recorded purchase, as shown in the inventory list.
 *
 * Gas and plain purchases live in separate tables and are unioned for display,
 * so `kind` says which one a row came from.
 */
export interface InventoryPurchase {
    /** Unique across both tables — the ids alone collide. */
    key: string;
    id: number;
    /** The product's `InventoryType`, e.g. `lpg_cylinder`. */
    kind: string;
    /** False once the edit window has closed or the correction limit is reached. */
    is_editable: boolean;
    /** When this record was last corrected, or null if never. */
    edited_at: string | null;
    display_name: string;
    /** The product bought — kept even after it leaves the catalogue. */
    catalogue: PurchaseCatalogue;
    supplier: string | null;
    invoice_ref: string | null;
    purchased_at: string;
    /** A swap exchanges empties for filled ones; it acquires no new shells. */
    is_refill: boolean;
    /** The product whose empties were sent, only on a cross-brand swap. */
    swapped_for: string | null;
    filled_quantity: number;
    empty_quantity: number;
    /** Per shell. Always 0 on a refill — those shells are already owned. */
    shell_unit_cost: number;
    /** Per unit: the gas in a cylinder, or the goods themselves. */
    unit_cost: number;
    /** For the whole consignment, not per unit. */
    transport_cost: number;
    /** Derived, never stored: units x cost, plus transport and other costs. */
    total_cost: number;
}

/** The catalogue item a purchase was recorded against. */
export interface PurchaseCatalogue {
    id: number;
    name: string | null;
    type: string;
    type_label: string;
    brand_name: string | null;
    weight: number;
    is_gas: boolean;
    is_returnable: boolean;
}

/**
 * An existing purchase in the shape the add/edit form uses.
 *
 * Every field is a string because they populate text inputs; the server casts
 * them back. Fields that do not apply to the purchase's kind come back empty.
 */
export interface PurchaseFormValues {
    id: number;
    catalogue_id: string;
    supplier: string;
    invoice_ref: string;
    purchased_at: string;
    swap_catalogue_id: string;
    filled_quantity: string;
    empty_quantity: string;
    shell_unit_cost: string;
    gas_unit_cost: string;
    quantity: string;
    unit_cost: string;
    transport_cost: string;
    /** Corrections already made, against the limit. */
    edits_used: number;
    edits_allowed: number;
    /** When the edit window closes. */
    editable_until: string;
}

/** A catalogue item in the purchase picker. */
export interface PurchasableItem {
    id: number;
    display_name: string;
    /** Decides which half of the purchase form applies. */
    is_gas: boolean;
    is_returnable: boolean;
}
