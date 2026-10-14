<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Why a planned activity was skipped. Stored as a code and translated in the
 * UI — the prototype stored the Croatian label itself ("Nije potrebno").
 */
enum CellarActivitySkipReason: string
{
    case NotNeeded = 'not_needed';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::NotNeeded => 'Not needed',
            self::Other => 'Other',
        };
    }
}
