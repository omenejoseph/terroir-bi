<?php

declare(strict_types=1);

namespace App\Enums;

/** The unit a kitchen item is counted in. */
enum KitchenUnit: string
{
    case Kilogram = 'kg';
    case Gram = 'g';
    case Litre = 'l';
    case Millilitre = 'ml';
    case Piece = 'pcs';
    case Pack = 'pack';
    case Bottle = 'bottle';

    public function label(): string
    {
        return match ($this) {
            self::Kilogram => 'kg',
            self::Gram => 'g',
            self::Litre => 'l',
            self::Millilitre => 'ml',
            self::Piece => 'pcs',
            self::Pack => 'pack',
            self::Bottle => 'bottle',
        };
    }
}
