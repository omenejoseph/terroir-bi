<?php

declare(strict_types=1);

namespace App\Enums;

/** Where a kitchen item is kept — the old app's two outlets. */
enum KitchenLocation: string
{
    case TastingRoom = 'tasting_room';
    case Restaurant = 'restaurant';

    public function label(): string
    {
        return match ($this) {
            self::TastingRoom => 'Tasting room',
            self::Restaurant => 'Restaurant',
        };
    }
}
