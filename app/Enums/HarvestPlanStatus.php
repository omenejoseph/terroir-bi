<?php

declare(strict_types=1);

namespace App\Enums;

/** Status of a harvest plan. */
enum HarvestPlanStatus: string
{
    case Planning = 'PLANNING';
    case Active = 'ACTIVE';
    case Complete = 'COMPLETE';
}
