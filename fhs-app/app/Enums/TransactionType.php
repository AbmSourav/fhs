<?php

namespace App\Enums;

/**
 * What a single order line actually sold.
 *
 * This lives on the line item, not the order: one sale can mix a cylinder swap
 * with a plain rice-bag sale, so there is no single answer per order.
 *
 * It determines both the stock movement and which cost basis applies.
 */
enum TransactionType: string
{
    /** Customer brought an empty and took a filled one — gas only. */
    case Swap = 'swap';

    /** Customer bought the cylinder and its gas outright — shell leaves for good. */
    case BuyWithGas = 'buy_with_gas';

    /** Customer bought a bare cylinder — shell only, no gas. */
    case BuyEmpty = 'buy_empty';

    /** A non-returnable good such as a rice sack. */
    case PlainSale = 'plain_sale';

    /**
     * Stock effect per unit sold, as [filled_stock_change, empty_stock_change].
     *
     * A swap is the only case that returns a shell, which is why filled and
     * empty must be tracked separately.
     */
    public function stockChangePerUnit(): array
    {
        return match ($this) {
            self::Swap       => ['filled' => -1, 'empty' => +1],
            self::BuyWithGas => ['filled' => -1, 'empty' => 0],
            self::BuyEmpty   => ['filled' => 0, 'empty' => -1],
            self::PlainSale  => ['filled' => -1, 'empty' => 0],
        };
    }

    /** Does this transaction consume gas? Drives which cost basis applies. */
    public function includesGas(): bool
    {
        return $this !== self::BuyEmpty;
    }

    /** Does the customer keep the shell? */
    public function includesShell(): bool
    {
        return $this === self::BuyWithGas || $this === self::BuyEmpty;
    }

    public function label(): string
    {
        return match ($this) {
            self::Swap       => 'Swap / refill',
            self::BuyWithGas => 'Buy cylinder with gas',
            self::BuyEmpty   => 'Buy empty cylinder',
            self::PlainSale  => 'Sale',
        };
    }
}
