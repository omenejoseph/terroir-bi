<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\Cellar;

use App\Http\Controllers\Controller;
use App\Models\Vessel;
use App\Queries\CellarPickerOptions;
use Illuminate\Http\JsonResponse;

/**
 * What the Record drawer (opened from any page) offers: the lots in the
 * cellar with the vessels they're in, the enological products in stock and
 * the finished items a bottling can go into. JSON, fetched when the drawer
 * first opens, so no page has to carry it.
 */
class RecordOptionsController extends Controller
{
    public function __invoke(CellarPickerOptions $options): JsonResponse
    {
        return response()->json(['data' => [
            'lots' => $options->lots(),
            // Destinations for moving wine: every active vessel with its room to spare.
            'vessels' => Vessel::query()->where('is_active', true)->orderBy('name')->get(['id', 'name', 'room', 'capacity_liters', 'current_volume'])
                ->map(fn (Vessel $v): array => [
                    'id' => (string) $v->getKey(),
                    'name' => $v->name,
                    'room' => $v->room,
                    'capacity_liters' => (string) $v->capacity_liters,
                    'current_volume' => (string) $v->current_volume,
                ])->values()->all(),
            'products' => $options->products(),
            'inventory_items' => $options->finishedItems(),
        ]]);
    }
}
