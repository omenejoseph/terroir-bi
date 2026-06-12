<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Lifecycle of an admin-authored BDD scenario. Since scenarios are executed
 * LIVE by an AI agent (no compile step), a saved scenario is immediately
 * READY. The compile-era cases (COMPILING, NEEDS_ACCESS, COMPILE_FAILED)
 * remain only so old rows keep deserializing; nothing writes them anymore and
 * a later cleanup migration retires them.
 */
enum BddScenarioStatus: string
{
    /** Saved without Gherkin (legacy; saves now go straight to READY). */
    case Draft = 'DRAFT';

    /** Legacy: a compile job was in flight. */
    case Compiling = 'COMPILING';

    /** The scenario is runnable. */
    case Ready = 'READY';

    /** Legacy: the compiler needed operations that hadn't been granted. */
    case NeedsAccess = 'NEEDS_ACCESS';

    /** Legacy: compilation failed. */
    case CompileFailed = 'COMPILE_FAILED';
}
