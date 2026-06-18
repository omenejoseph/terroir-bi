<?php

declare(strict_types=1);

namespace App\Actions\Cellar;

use App\Models\Vessel;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Create a run of similar vessels with sequential names (e.g. F1…F50). Capped at
 * 50 per call to keep it a single quick transaction.
 */
class BulkCreateVesselsAction
{
    private const MAX = 50;

    /**
     * @param  array<string, mixed>  $data
     * @return Collection<int, Vessel>
     */
    public function execute(array $data): Collection
    {
        $count = (int) $data['count'];
        if ($count < 1 || $count > self::MAX) {
            throw ValidationException::withMessages([
                'count' => 'Create between 1 and '.self::MAX.' vessels at a time.',
            ]);
        }

        $prefix = (string) ($data['prefix'] ?? '');
        $start = (int) ($data['start_number'] ?? 1);

        return DB::transaction(function () use ($data, $count, $prefix, $start): Collection {
            $vessels = new Collection;
            for ($i = 0; $i < $count; $i++) {
                $vessels->push(Vessel::create([
                    'name' => $prefix.($start + $i),
                    'type' => $data['type'],
                    'material' => $data['material'] ?? null,
                    'capacity_liters' => $data['capacity_liters'],
                    'room' => $data['room'] ?? 'Main Cellar',
                ]));
            }

            return $vessels;
        });
    }
}
