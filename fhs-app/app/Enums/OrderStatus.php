<?php

namespace App\Enums;

/**
 * Fulfilment state of an order — deliberately not payment state.
 *
 * Payment state is derived from the payments table; the two are independent
 * (an order can be Complete but unpaid, or Failed after a deposit was taken).
 *
 * Sales are currently recorded after the fact, so orders are created as
 * Complete. The other cases exist so an order lifecycle can be introduced
 * later without a migration.
 */
enum OrderStatus: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case Complete = 'complete';
    case Failed = 'failed';

    /**
     * Did this order actually happen? Failed orders must not consume stock,
     * count toward revenue, or be billed to the customer.
     */
    public function didHappen(): bool
    {
        return $this !== self::Failed;
    }

    public function label(): string
    {
        return match ($this) {
            self::Pending    => 'Pending',
            self::Processing => 'Processing',
            self::Complete   => 'Complete',
            self::Failed     => 'Failed',
        };
    }
}
