<?php

declare(strict_types=1);

namespace App\Enums;

/** Whether a vineyard parcel is estate-owned or farmed by a cooperant grower. */
enum ParcelOwnership: string
{
    case Own = 'OWN';
    case Cooperant = 'COOPERANT';
}
