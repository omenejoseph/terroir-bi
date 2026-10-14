<?php

declare(strict_types=1);

namespace App\Enums;

enum CellarActivityStatus: string
{
    case Planned = 'PLANNED';
    case Done = 'DONE';
    case Skipped = 'SKIPPED';
}
