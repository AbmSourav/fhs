<?php

namespace App\Enums;

/**
 * What kind of user an account is.
 *
 * Stored inside the `permission` JSON column rather than in a column of its
 * own, so adding a role stays a code change with no migration.
 *
 * Administrators are deliberately absent: they are identified by email address
 * through `ADMIN_EMAILS`, so the admin set is fixed by deployment and cannot be
 * granted from inside the application.
 */
enum UserRole: string
{
    /** Puts money in and reads the figures. */
    case Investor = 'investor';

    /** Runs the business day to day. */
    case Founder = 'founder';

    public function label(): string
    {
        return match ($this) {
            self::Investor => 'Investor',
            self::Founder  => 'Founder',
        };
    }

    /** The choices behind a role select. */
    public static function options(): array
    {
        return array_map(
            fn (self $role) => ['value' => $role->value, 'label' => $role->label()],
            self::cases(),
        );
    }

    public static function roles(): array
    {
        return [
            self::Investor->value => self::Investor->label(),
            self::Founder->value  => self::Founder->label(),
        ];
    }
}
