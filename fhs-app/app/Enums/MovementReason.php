<?php

namespace App\Enums;

/**
 * Why a stock movement happened.
 *
 * The movement log is append-only: mistakes are corrected by appending a
 * reversing row, never by editing or deleting one.
 */
enum MovementReason: string
{
    /** New stock received from a supplier. */
    case Purchase = 'purchase';

    /** A plain good sold. */
    case Sale = 'sale';

    /** Customer swapped an empty for a filled one. */
    case Swap = 'swap';

    /** Empties sent to the supplier, filled cylinders back. No shells acquired. */
    case Refill = 'refill';

    /** Stocktake correction or other manual adjustment. Requires a note. */
    case Adjustment = 'adjustment';

    /** Reversal of an earlier movement, e.g. a cancelled order. */
    case Reversal = 'reversal';

    /** Does this reason require an explanatory note? */
    public function requiresNote(): bool
    {
        return $this === self::Adjustment || $this === self::Reversal;
    }

    public function label(): string
    {
        return match ($this) {
            self::Purchase => 'Purchase',
            self::Sale => 'Sale',
            self::Swap => 'Swap',
            self::Refill => 'Refill',
            self::Adjustment => 'Adjustment',
            self::Reversal => 'Reversal',
        };
    }
}
