<?php

declare(strict_types=1);

namespace App\Enums;

/** Outcome (or in-flight state) of one live execution of a BDD scenario. */
enum BddRunStatus: string
{
    /** Created and waiting for a queue worker to pick it up. */
    case Queued = 'QUEUED';

    /** The AI agent is executing the scenario right now. */
    case Running = 'RUNNING';

    /** Every step executed and every assertion held. */
    case Pass = 'PASS';

    /** A step's assertion failed (the app's behaviour diverged). */
    case Fail = 'FAIL';

    /** The run itself broke (unexpected exception, no judgement…). */
    case Error = 'ERROR';

    /** A step referenced an operation that is not granted. */
    case NeedsAccess = 'NEEDS_ACCESS';

    /** Whether the run is still queued or executing (the UI polls while true). */
    public function isInFlight(): bool
    {
        return $this === self::Queued || $this === self::Running;
    }
}
