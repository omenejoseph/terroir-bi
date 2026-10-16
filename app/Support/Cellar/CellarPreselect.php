<?php

declare(strict_types=1);

namespace App\Support\Cellar;

use App\Enums\CellarActivityType;
use App\Models\Vessel;
use App\Models\WineLot;
use Illuminate\Http\Request;

/**
 * What a cellar page should start with selected, from the query string — the
 * map's action bar links here with `?vessels=a,b` (and Plan with `?type=`),
 * the attention list and the lot page with `?lots=a`. Ids are kept only when
 * they are this tenant's (models are tenant-scoped), so a pasted link can't
 * reach another winery's records.
 */
final class CellarPreselect
{
    /**
     * @return array{vessels: list<string>, lots: list<string>, type: string|null}
     */
    public static function from(Request $request): array
    {
        $ids = fn (string $key): array => array_values(array_filter(array_map('trim', explode(',', (string) $request->query($key, ''))), fn (string $id): bool => $id !== ''));

        $vessels = $ids('vessels');
        $lots = $ids('lots');
        $type = CellarActivityType::tryFrom(strtoupper((string) $request->query('type', '')));

        return [
            'vessels' => $vessels === [] ? [] : array_values(array_map('strval', Vessel::query()->whereIn('id', array_slice($vessels, 0, 200))->pluck('id')->all())),
            'lots' => $lots === [] ? [] : array_values(array_map('strval', WineLot::query()->whereIn('id', array_slice($lots, 0, 200))->pluck('id')->all())),
            'type' => $type?->value,
        ];
    }
}
