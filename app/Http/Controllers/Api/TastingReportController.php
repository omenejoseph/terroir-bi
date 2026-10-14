<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\Cellar\TastingReports\DeleteTastingReportAction;
use App\Actions\Cellar\TastingReports\SaveTastingReportAction;
use App\DataTransferObjects\TastingReportData;
use App\Http\Controllers\Controller;
use App\Http\Requests\Cellar\StoreTastingReportRequest;
use App\Models\TastingReport;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Tasting reports: a dated panel of wines, each also a note on its lot. */
class TastingReportController extends Controller
{
    private const WITH = ['notes.wineLot', 'notes.vessel', 'createdBy'];

    public function index(): JsonResponse
    {
        $reports = TastingReport::query()->with(self::WITH)->orderByDesc('date')->get();

        return response()->json([
            'data' => $reports->map(fn (TastingReport $r): array => TastingReportData::fromModel($r)->toArray())->all(),
        ]);
    }

    public function show(TastingReport $tastingReport): JsonResponse
    {
        return response()->json(['data' => TastingReportData::fromModel($tastingReport->load(self::WITH), withDetail: true)->toArray()]);
    }

    public function store(StoreTastingReportRequest $request, SaveTastingReportAction $action): JsonResponse
    {
        /** @var array{title?: string|null, date?: string|null, note?: string|null, wines?: list<array{wine_lot_id: string}>} $data */
        $data = $request->validated();
        $report = $action->execute(null, $data, $this->userId($request));

        return response()->json(['data' => TastingReportData::fromModel($report->load(self::WITH), withDetail: true)->toArray()], 201);
    }

    public function update(StoreTastingReportRequest $request, TastingReport $tastingReport, SaveTastingReportAction $action): JsonResponse
    {
        /** @var array{title?: string|null, date?: string|null, note?: string|null, wines?: list<array{wine_lot_id: string}>} $data */
        $data = $request->validated();
        $report = $action->execute($tastingReport, $data, $this->userId($request));

        return response()->json(['data' => TastingReportData::fromModel($report->load(self::WITH), withDetail: true)->toArray()]);
    }

    public function destroy(TastingReport $tastingReport, DeleteTastingReportAction $action): JsonResponse
    {
        $action->execute($tastingReport);

        return response()->json(status: 204);
    }

    private function userId(Request $request): string
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return $user->getKey();
    }
}
