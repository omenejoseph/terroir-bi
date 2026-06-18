<?php

declare(strict_types=1);

namespace App\Enums;

/** Vine growth stage recorded during the season. */
enum PhenologyStage: string
{
    case BudBreak = 'BUD_BREAK';
    case Flowering = 'FLOWERING';
    case FruitSet = 'FRUIT_SET';
    case Veraison = 'VERAISON';
    case HarvestReady = 'HARVEST_READY';
}
