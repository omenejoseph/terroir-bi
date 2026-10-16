<?php

declare(strict_types=1);

namespace App\Actions\Cellar;

use App\Models\Vessel;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Delete several vessels at once (the map's "Delete" on a selection, as the prototype had it). A
 * vessel holding wine is never deleted — it is skipped and reported, so one wrong click in a big
 * selection cannot take wine out of the books — and ids from another winery are simply not found.
 */
class BulkDeleteVesselsAction
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  list<string>  $ids
     * @return array{deleted: int, kept: list<string>} `kept` names the vessels that hold wine
     */
    public function execute(array $ids): array
    {
        return DB::transaction(function () use ($ids): array {
            $vessels = Vessel::query()->whereIn('id', $ids)->withCount('vesselLots')->get();
            $deleted = [];
            $kept = [];

            foreach ($vessels as $vessel) {
                if ($vessel->vessel_lots_count > 0) {
                    $kept[] = $vessel->name;

                    continue;
                }
                $deleted[] = $vessel->name;
                $vessel->delete();
            }

            if ($deleted !== []) {
                $this->audit->record(Auth::user(), 'vessel.bulk_deleted', null, ['count' => count($deleted), 'names' => $deleted]);
            }

            return ['deleted' => count($deleted), 'kept' => $kept];
        });
    }
}
