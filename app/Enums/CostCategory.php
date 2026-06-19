<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Canonical cost categories — the single source of truth for the category names
 * that computations rely on (employee-cost %, marketing %, invoiced/paid cards).
 *
 * `Cost.category` is free text so operators can add their own, but the values
 * below are the ones the dashboard matches against. They're surfaced in the cost
 * category picker for EVERY tenant (see CostController::categories) so a brand-new
 * tenant — and every deploy — has them without per-tenant seeding or drift.
 * Matching is case-insensitive so "salary"/"Salary" both count.
 */
enum CostCategory: string
{
    case Salary = 'Salary';
    case Marketing = 'Marketing';
    case Invoice = 'Invoice';
    case Payment = 'Payment';
    case Utilities = 'Utilities';
    case Packaging = 'Packaging';
    case Equipment = 'Equipment';
    case Logistics = 'Logistics';
    case Operations = 'Operations';

    /**
     * Canonical category names offered to every tenant in the picker.
     *
     * @return list<string>
     */
    public static function defaults(): array
    {
        return array_map(fn (self $c): string => $c->value, self::cases());
    }

    /** Case-insensitive match against a stored (free-text) category value. */
    public function matches(?string $raw): bool
    {
        return $raw !== null && mb_strtolower(trim($raw)) === mb_strtolower($this->value);
    }

    /** Lower-cased value, for `LOWER(category) = ?` comparisons in SQL. */
    public function sqlLower(): string
    {
        return mb_strtolower($this->value);
    }
}
