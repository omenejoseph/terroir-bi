<?php

declare(strict_types=1);

namespace App\Services\Bdd;

use Filament\Facades\Filament;

/**
 * Normalises the Filament auth id (int|string|null) to the ?string our BDD
 * actions/services expect. Users are ULID-keyed, so the id is already a string
 * in practice — this just satisfies the type contract in one place.
 */
final class CurrentOperator
{
    public static function id(): ?string
    {
        $id = Filament::auth()->id();

        return $id === null ? null : (string) $id;
    }
}
