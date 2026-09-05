<?php

declare(strict_types=1);

namespace App\Services\Bdd;

use Illuminate\Support\Facades\Auth;

/**
 * Normalises the authenticated user's id (int|string|null) to the ?string our
 * BDD actions/services expect. Users are ULID-keyed, so the id is already a
 * string in practice — this just satisfies the type contract in one place.
 *
 * Was Filament::auth()->id() while the back office was a Filament panel;
 * both the platform.admin route group and the (now-retired) Filament panel
 * authenticated through the same 'web' guard, so this is not a behaviour
 * change — just dropping the Filament dependency.
 */
final class CurrentOperator
{
    public static function id(): ?string
    {
        $id = Auth::id();

        return $id === null ? null : (string) $id;
    }
}
