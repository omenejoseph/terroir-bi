<?php

declare(strict_types=1);

namespace App\Actions\Cellar;

use App\Models\Vessel;
use App\Services\Audit\AuditLogger;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Map editor "Duplicate N": copy each selected vessel `count` times with the
 * same specs, naming each copy with the next free number for its name prefix
 * at the same zero-padding (F09 → F10, F11, …) and placing it just beside the
 * original on the map.
 */
class DuplicateVesselsAction
{
    private const MAX = 50;

    private const OFFSET = 20;

    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  list<string>  $vesselIds
     * @return Collection<int, Vessel>
     */
    public function execute(array $vesselIds, int $count): Collection
    {
        if ($count < 1 || $count * count($vesselIds) > self::MAX) {
            throw ValidationException::withMessages(['count' => 'Duplicate between 1 and '.self::MAX.' vessels at a time.']);
        }

        return DB::transaction(function () use ($vesselIds, $count): Collection {
            $taken = Vessel::query()->pluck('name')->map(fn ($n): string => mb_strtolower((string) $n))->flip()->all();
            $created = new Collection;

            foreach (Vessel::query()->whereIn('id', $vesselIds)->orderBy('name')->get() as $source) {
                /** @var Vessel $source */
                [$prefix, $number, $width] = $this->split($source->name);

                for ($i = 1; $i <= $count; $i++) {
                    do {
                        $number++;
                        $name = $prefix.str_pad((string) $number, $width, '0', STR_PAD_LEFT);
                    } while (isset($taken[mb_strtolower($name)]));
                    $taken[mb_strtolower($name)] = true;

                    $created->push(Vessel::create([
                        'name' => $name,
                        'type' => $source->type,
                        'material' => $source->material,
                        'capacity_liters' => $source->capacity_liters,
                        'location' => $source->location,
                        'room' => $source->room,
                        'map_width' => $source->map_width,
                        'map_height' => $source->map_height,
                        'rotation' => $source->rotation,
                        'position_x' => $source->position_x !== null ? $source->position_x + self::OFFSET * $i : null,
                        'position_y' => $source->position_y !== null ? $source->position_y + self::OFFSET * $i : null,
                    ]));
                }
            }

            $this->audit->record(Auth::user(), 'vessel.duplicated', null, [
                'source_ids' => $vesselIds,
                'created' => $created->map(fn (Vessel $v): string => $v->name)->all(),
            ]);

            return $created;
        });
    }

    /**
     * "F09" → ["F", 9, 2]; "Barrel" → ["Barrel ", 0, 1].
     *
     * @return array{0: string, 1: int, 2: int}
     */
    private function split(string $name): array
    {
        if (preg_match('/^(.*?)(\d+)$/', $name, $m) === 1) {
            return [$m[1], (int) $m[2], strlen($m[2])];
        }

        return [$name.' ', 0, 1];
    }
}
