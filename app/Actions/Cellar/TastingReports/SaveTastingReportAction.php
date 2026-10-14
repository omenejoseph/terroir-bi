<?php

declare(strict_types=1);

namespace App\Actions\Cellar\TastingReports;

use App\Models\CellarTastingNote;
use App\Models\TastingReport;
use Illuminate\Support\Facades\DB;

/**
 * Create or edit a tasting report. Each wine in the report is also a tasting
 * note on its lot (so the lot's Tasting tab shows it); editing the report
 * adds, updates or removes those notes by lot, as the prototype did.
 */
class SaveTastingReportAction
{
    /**
     * @param  array{title?: string|null, date?: string|null, note?: string|null, wines?: list<array{wine_lot_id: string, vessel_id?: string|null, score?: int|null, appearance?: string|null, nose?: string|null, palate?: string|null, overall?: string|null, note?: string|null}>}  $data
     */
    public function execute(?TastingReport $report, array $data, string $userId): TastingReport
    {
        return DB::transaction(function () use ($report, $data, $userId): TastingReport {
            if ($report === null) {
                $report = TastingReport::create([
                    'created_by_id' => $userId,
                    'title' => $data['title'] ?? null,
                    'date' => $data['date'] ?? now(),
                    'note' => $data['note'] ?? null,
                ]);
            } else {
                $report->fill(array_intersect_key($data, array_flip(['title', 'date', 'note'])));
                $report->save();
            }

            if (array_key_exists('wines', $data)) {
                $this->syncWines($report, $data['wines'], $userId);
            }

            return $report;
        });
    }

    /**
     * @param  list<array<string, mixed>>  $wines
     */
    private function syncWines(TastingReport $report, array $wines, string $userId): void
    {
        $existing = $report->notes()->get()->keyBy('wine_lot_id');
        $seen = [];

        foreach ($wines as $wine) {
            $lotId = (string) $wine['wine_lot_id'];
            $seen[$lotId] = true;
            $fields = [
                'vessel_id' => $wine['vessel_id'] ?? null,
                'date' => $report->date,
                'score' => isset($wine['score']) ? (int) $wine['score'] : null,
                'appearance' => $wine['appearance'] ?? null,
                'nose' => $wine['nose'] ?? null,
                'palate' => $wine['palate'] ?? null,
                'overall' => $wine['overall'] ?? null,
                'note' => $wine['note'] ?? null,
            ];

            $note = $existing[$lotId] ?? null;
            if ($note instanceof CellarTastingNote) {
                $note->fill($fields)->save();
            } else {
                CellarTastingNote::create([
                    ...$fields,
                    'wine_lot_id' => $lotId,
                    'tasting_report_id' => $report->getKey(),
                    'created_by_id' => $userId,
                ]);
            }
        }

        foreach ($existing as $lotId => $note) {
            if (! isset($seen[(string) $lotId])) {
                $note->delete();
            }
        }
    }
}
