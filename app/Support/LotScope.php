<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\WineLot;

/**
 * Which part of a lot the page is showing: the whole lot, or one of the vessels (barrels) it sits in.
 *
 * Carried in the URL as `?vessel=<id>` so a barrel view can be linked to, and resolved here once so
 * every table, chart and figure on the lot page agrees on it. A vessel the lot is not in (a stale
 * link, a barrel the wine has since left) is not a scope: the page falls back to the whole lot
 * instead of showing an empty page.
 */
final class LotScope
{
    public static function resolve(WineLot $lot, ?string $requested): ?string
    {
        if ($requested === null || $requested === '') {
            return null;
        }

        return $lot->vesselLots()->where('vessel_id', $requested)->exists() ? $requested : null;
    }
}
