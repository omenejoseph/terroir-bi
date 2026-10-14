<?php

declare(strict_types=1);

namespace App\Enums;

/** Why an enological product's stock changed. */
enum EnologicalStockSource: string
{
    case Opening = 'opening';
    case Addition = 'addition';
    case AdditionCorrected = 'addition_corrected';
    case AdditionReversed = 'addition_reversed';
    case Manual = 'manual';
    case Import = 'import';
}
