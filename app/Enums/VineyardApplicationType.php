<?php

declare(strict_types=1);

namespace App\Enums;

/** Type of vineyard treatment applied to a parcel. */
enum VineyardApplicationType: string
{
    case Spray = 'SPRAY';
    case Fertilizer = 'FERTILIZER';
    case Herbicide = 'HERBICIDE';
    case Other = 'OTHER';
}
