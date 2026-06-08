<?php

declare(strict_types=1);

namespace App\Enums;

enum InventoryCategory: string
{
    case Finished = 'FINISHED';
    case SemiFinished = 'SEMI_FINISHED';
    case RawMaterial = 'RAW_MATERIAL';
}
