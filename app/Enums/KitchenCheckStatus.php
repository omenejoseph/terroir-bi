<?php

declare(strict_types=1);

namespace App\Enums;

/** A stock-take (the old app's "Inventura"): being counted, or done and applied to stock. */
enum KitchenCheckStatus: string
{
    case InProgress = 'IN_PROGRESS';
    case Completed = 'COMPLETED';
}
