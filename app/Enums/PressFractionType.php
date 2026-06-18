<?php

declare(strict_types=1);

namespace App\Enums;

/** Press fraction quality tier. */
enum PressFractionType: string
{
    case FreeRun = 'FREE_RUN';
    case LightPress = 'LIGHT_PRESS';
    case HardPress = 'HARD_PRESS';
    case Must = 'MUST';
}
