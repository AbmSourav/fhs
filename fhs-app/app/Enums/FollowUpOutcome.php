<?php

namespace App\Enums;

/**
 * How a follow-up call went.
 *
 * Recorded so a call list can tell a productive call from an unanswered one.
 * Without it, someone who has already said no keeps resurfacing alongside
 * someone nobody has managed to reach yet.
 */
enum FollowUpOutcome: string
{
    /** Spoke to them; nothing further promised. */
    case Reached = 'reached';

    /** Nobody picked up, so they are still worth trying. */
    case NoAnswer = 'no_answer';

    /** Said they will buy, but not yet. */
    case WillBuyLater = 'will_buy_later';

    /** Ordered off the back of the call. */
    case Ordered = 'ordered';

    /** Asked not to be contacted about this again. */
    case NotInterested = 'not_interested';

    public function label(): string
    {
        return match ($this) {
            self::Reached       => 'Reached',
            self::NoAnswer      => 'No answer',
            self::WillBuyLater  => 'Will buy later',
            self::Ordered       => 'Ordered',
            self::NotInterested => 'Not interested',
        };
    }

    /**
     * Did this settle the matter, or is the customer still worth calling?
     *
     * A missed call leaves them exactly as they were, so it should not count as
     * having followed them up.
     */
    public function isConclusive(): bool
    {
        return $this !== self::NoAnswer;
    }
}
