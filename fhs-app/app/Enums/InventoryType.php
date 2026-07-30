<?php

namespace App\Enums;

/**
 * Kinds of goods the business sells.
 *
 * Stored as a plain string on `catalogue`, backed by this enum rather than a
 * lookup table — adding a type is a code change with no migration, and the enum
 * still prevents the free-text drift that would split one product's stock
 * across two spellings.
 *
 * `catalogue.is_gas` and `is_returnable` are stored columns because the queries
 * filter on them, but they should be set from the defaults here.
 */
enum InventoryType: string
{
    case LpgCylinder = 'lpg_cylinder';
    case RiceBag = 'rice_bag';

    /** Bought through gas_inventory_purchases, with shell and gas costed separately. */
    public function isGas(): bool
    {
        return match ($this) {
            self::LpgCylinder => true,
            self::RiceBag     => false,
        };
    }

    /** Does the container come back? Drives empty-shell tracking. */
    public function isReturnable(): bool
    {
        return match ($this) {
            self::LpgCylinder => true,
            self::RiceBag     => false,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::LpgCylinder => 'LPG cylinder',
            self::RiceBag     => 'Rice bag',
        };
    }
}
