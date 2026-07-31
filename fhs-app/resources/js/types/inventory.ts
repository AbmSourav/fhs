/**
 * A recorded purchase, as shown in the inventory list.
 *
 * Gas and plain purchases live in separate tables and are unioned for display,
 * so `kind` says which one a row came from.
 */
export interface InventoryPurchase {
    /** Unique across both tables — the ids alone collide. */
    key: string;
    /** The product's `InventoryType`, e.g. `lpg_cylinder`. */
    kind: string;
    display_name: string;
    /** The product bought — kept even after it leaves the catalogue. */
    catalogue: PurchaseCatalogue;
    supplier: string | null;
    invoice_ref: string | null;
    purchased_at: string;
    /** A refill exchanges empties for filled ones; it acquires no new shells. */
    is_refill: boolean;
    filled_quantity: number;
    empty_quantity: number;
    /** Per shell. Always 0 on a refill — those shells are already owned. */
    shell_unit_cost: number;
    /** Per unit: the gas in a cylinder, or the goods themselves. */
    unit_cost: number;
    /** For the whole consignment, not per unit. */
    transport_cost: number;
    other_cost: number;
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

/** A catalogue item in the purchase picker. */
export interface PurchasableItem {
    id: number;
    display_name: string;
    /** Decides which half of the purchase form applies. */
    is_gas: boolean;
    is_returnable: boolean;
}
