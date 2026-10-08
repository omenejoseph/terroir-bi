<?php

declare(strict_types=1);

namespace App\Enums;

enum TaskStatus: string
{
    case Todo = 'TODO';
    case InProgress = 'IN_PROGRESS';
    case Done = 'DONE';
    case Cancelled = 'CANCELLED';

    public function isDone(): bool
    {
        return $this === self::Done;
    }

    /** Finished one way or the other: no longer open work (not due, not overdue). */
    public function isClosed(): bool
    {
        return $this === self::Done || $this === self::Cancelled;
    }

    /** @return list<self> */
    public static function closed(): array
    {
        return [self::Done, self::Cancelled];
    }
}
