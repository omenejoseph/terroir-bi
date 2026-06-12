<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Lifecycle of an admin-authored BDD scenario: written → AI-compiled into an
 * execution plan → ready to run, or parked when access is missing.
 */
enum BddScenarioStatus: string
{
    /** Saved but not yet compiled. */
    case Draft = 'DRAFT';

    /** A compile job is in flight. */
    case Compiling = 'COMPILING';

    /** Compiled plan saved; the scenario is runnable. */
    case Ready = 'READY';

    /** The compiler needs operations that haven't been granted (fail-closed). */
    case NeedsAccess = 'NEEDS_ACCESS';

    /** Compilation failed (AI error or invalid plan). */
    case CompileFailed = 'COMPILE_FAILED';

    public function isRunnable(): bool
    {
        return $this === self::Ready;
    }
}
