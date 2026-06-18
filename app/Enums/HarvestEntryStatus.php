<?php

declare(strict_types=1);

namespace App\Enums;

/** Lifecycle of a harvest entry from planning through reception. */
enum HarvestEntryStatus: string
{
    case Planned = 'PLANNED';
    case Harvested = 'HARVESTED';
    case Processed = 'PROCESSED';
}
