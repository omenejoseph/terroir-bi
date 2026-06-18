<?php

declare(strict_types=1);

namespace App\Enums;

/** A wine lot's lifecycle stage from fermentation through to bottling. */
enum WineLotStatus: string
{
    case Fermenting = 'FERMENTING';
    case Aging = 'AGING';
    case Ready = 'READY';
    case Bottled = 'BOTTLED';
    case Blended = 'BLENDED';
}
