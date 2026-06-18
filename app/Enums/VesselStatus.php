<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * A vessel's occupancy/availability state. Derived from its wine occupancy by
 * VesselVolumeSync (AVAILABLE when empty, IN_USE when holding wine); MAINTENANCE
 * and RETIRED are set manually.
 */
enum VesselStatus: string
{
    case Available = 'AVAILABLE';
    case InUse = 'IN_USE';
    case Maintenance = 'MAINTENANCE';
    case Retired = 'RETIRED';
}
