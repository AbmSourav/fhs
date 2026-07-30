/**
 * An option in the product-type picker, mirroring the PHP InventoryType enum.
 *
 * `is_gas` and `is_returnable` are sent so the form can explain what choosing a
 * type implies. The server never trusts them from the client — it derives both
 * from the enum when creating the record.
 */
export interface InventoryTypeOption {
    value: string;
    label: string;
    is_gas: boolean;
    is_returnable: boolean;
}
