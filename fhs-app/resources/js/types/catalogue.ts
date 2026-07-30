/**
 * A product the business sells — one record per type + brand + weight.
 *
 * Stock is not stored on the record; `filled_stock` and `empty_stock` are
 * derived by summing the movement log, so they are only present on payloads
 * that asked for them.
 */
export interface CatalogueItem {
    id: number;
    /** Optional own name; when null, `display_name` is generated from brand + type + weight. */
    name: string | null;
    type: string;
    type_label: string;
    brand_name: string | null;
    weight: number;
    /** Bought through the gas purchase flow, with shell and gas costed separately. */
    is_gas: boolean;
    /** Cylinders come back, so empty shells are tracked. Rice sacks do not. */
    is_returnable: boolean;
    display_name: string;
    filled_stock: number;
    empty_stock: number;
    /** Negative stock is allowed and flagged, not prevented. */
    has_negative_stock: boolean;
}
