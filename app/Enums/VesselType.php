<?php

declare(strict_types=1);

namespace App\Enums;

/** A cellar vessel's physical type. */
enum VesselType: string
{
    case Barrel = 'BARREL';
    case Barrique = 'BARRIQUE';
    case Tank = 'TANK';
    case Vat = 'VAT';
    case Amphora = 'AMPHORA';
}
