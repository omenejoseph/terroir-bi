<?php

declare(strict_types=1);

namespace App\Enums;

enum TaskPriority: string
{
    case Low = 'LOW';
    case Medium = 'MEDIUM';
    case High = 'HIGH';
}
