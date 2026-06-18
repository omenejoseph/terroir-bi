<?php

declare(strict_types=1);

namespace App\Enums;

/** Origin of harvested grapes. */
enum HarvestSource: string
{
    case Own = 'OWN';
    case Contract = 'CONTRACT';
    case External = 'EXTERNAL';
}
