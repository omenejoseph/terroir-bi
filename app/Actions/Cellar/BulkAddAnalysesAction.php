<?php

declare(strict_types=1);

namespace App\Actions\Cellar;

use App\Models\CellarAnalysis;
use App\Models\WineLot;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Insert several analyses in one transaction (e.g. a batch of lab results keyed
 * by lot). Each row names its own wine_lot_id; rows for lots in another tenant
 * are rejected by the tenant scope when the lot is resolved.
 */
class BulkAddAnalysesAction
{
    public function __construct(
        private readonly AddCellarAnalysisAction $add,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<CellarAnalysis>
     */
    public function execute(array $rows, string $createdById): array
    {
        return DB::transaction(function () use ($rows, $createdById): array {
            $created = [];

            foreach ($rows as $row) {
                $lot = WineLot::query()->whereKey((string) $row['wine_lot_id'])->firstOrFail();
                $created[] = $this->add->execute($lot, $row, $createdById);
            }

            // One summary entry for the batch (e.g. a whole-cellar SO₂ round of readings).
            $this->audit->record(Auth::user(), 'cellar.bulk_analyses_recorded', null, [
                'count' => count($created),
                'wine_lot_ids' => array_values(array_unique(array_map(fn (CellarAnalysis $a): string => (string) $a->wine_lot_id, $created))),
            ]);

            return $created;
        });
    }
}
