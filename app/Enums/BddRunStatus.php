<?php

declare(strict_types=1);

namespace App\Enums;

/** Outcome of one replay of a compiled BDD scenario. */
enum BddRunStatus: string
{
    /** Every step executed and every assertion held. */
    case Pass = 'PASS';

    /** A step's assertion failed (the app's behaviour diverged). */
    case Fail = 'FAIL';

    /** The run itself broke (unexpected exception, invalid plan…). */
    case Error = 'ERROR';

    /** A step referenced an operation that is no longer granted. */
    case NeedsAccess = 'NEEDS_ACCESS';
}
