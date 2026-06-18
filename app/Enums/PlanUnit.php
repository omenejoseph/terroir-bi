<?php

declare(strict_types=1);

namespace App\Enums;

/** The unit a production plan row is expressed in. */
enum PlanUnit: string
{
    case Liters = 'liters';
    case Bottles = 'bottles';
    case Cases = 'cases';
}
