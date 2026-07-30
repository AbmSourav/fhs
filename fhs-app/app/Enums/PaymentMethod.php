<?php

namespace App\Enums;

/**
 * How money changed hands.
 *
 * All payment is offline — the application never processes payments and has no
 * gateway integration.
 */
enum PaymentMethod: string
{
    case Cash = 'cash';
    case Mobile = 'mobile';

    public function label(): string
    {
        return match ($this) {
            self::Cash   => 'Cash',
            self::Mobile => 'Mobile payment',
        };
    }
}
