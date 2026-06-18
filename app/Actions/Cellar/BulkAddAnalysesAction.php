<?php

declare(strict_types=1);

namespace App\Actions\Cellar;

use App\Models\CellarAnalysis;
use App\Models\WineLot;
use Illuminate\Support\Facades\DB;

/**
 * Insert several analyses in one transaction (e.g. a batch of lab results keyed
 * by lot). Each row names its own wine_lot_id; rows for lots in another tenant
 * are rejected by the tenant scope when the lot is resolved.
 */
class BulkAddAnalysesAction
{
    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<CellarAnalysis>
     */
    public function execute(array $rows, string $createdById): array
    {
        return DB::transaction(function () use ($rows, $createdById): array {
            $add = new AddCellarAnalysisAction;
            $created = [];

            foreach ($rows as $row) {
                $lot = WineLot::query()->whereKey((string) $row['wine_lot_id'])->firstOrFail();
                $created[] = $add->execute($lot, $row, $createdById);
            }

            return $created;
        });
    }
}
