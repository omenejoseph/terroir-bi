<?php

declare(strict_types=1);

namespace App\Actions\Cellar\TastingReports;

use App\Models\TastingReport;
use Illuminate\Support\Facades\DB;

/** Delete a tasting report together with the lot notes it created. */
class DeleteTastingReportAction
{
    public function execute(TastingReport $report): void
    {
        DB::transaction(function () use ($report): void {
            $report->notes()->get()->each->delete();
            $report->delete();
        });
    }
}
