<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Per-line review state. Only `approved` or `edited` lines are committed; an
 * `edited` line carries a user-corrected payload, a `rejected` line is dropped.
 */
enum AiImportLineStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Edited = 'edited';
    case Rejected = 'rejected';
    case Committed = 'committed';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Needs review',
            self::Approved => 'Approved',
            self::Edited => 'Edited',
            self::Rejected => 'Rejected',
            self::Committed => 'Committed',
        };
    }

    /** Whether a line in this state should be written to the database on commit. */
    public function isCommittable(): bool
    {
        return $this === self::Approved || $this === self::Edited;
    }
}
