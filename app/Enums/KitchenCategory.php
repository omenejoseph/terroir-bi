<?php

declare(strict_types=1);

namespace App\Enums;

/** What a kitchen item is: bought in, prepared in-house, or ready to serve. */
enum KitchenCategory: string
{
    case RawMaterial = 'RAW_MATERIAL';
    case SemiFinished = 'SEMI_FINISHED';
    case Finished = 'FINISHED';

    public function label(): string
    {
        return match ($this) {
            self::RawMaterial => 'Raw material',
            self::SemiFinished => 'Semi-finished',
            self::Finished => 'Finished',
        };
    }
}
