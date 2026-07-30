<?php

namespace App\Enums;

/**
 * Categories for non-stock business spending.
 *
 * Stored as a plain string column rather than a database enum, so adding a
 * category is a code change with no migration. The enum still prevents the
 * "Fuel" / "fuel" / "petrol" fragmentation that free text invites.
 */
enum ExpenseCategory: string
{
    /** Tools and hardware — a padlock, a chain, a trolley. */
    case Equipment = 'equipment';

    /** General vehicle running costs. Consignment-specific transport belongs on the purchase row. */
    case Transport = 'transport';

    case Utilities = 'utilities';
    case Salary = 'salary';
    case Rent = 'rent';
    case Maintenance = 'maintenance';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Equipment   => 'Equipment',
            self::Transport   => 'Transport',
            self::Utilities   => 'Utilities',
            self::Salary      => 'Salary',
            self::Rent        => 'Rent',
            self::Maintenance => 'Maintenance',
            self::Other       => 'Other',
        };
    }
}
