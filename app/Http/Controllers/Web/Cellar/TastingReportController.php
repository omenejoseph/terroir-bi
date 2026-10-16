<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\Cellar;

use App\Actions\Cellar\TastingReports\DeleteTastingReportAction;
use App\Actions\Cellar\TastingReports\SaveTastingReportAction;
use App\DataTransferObjects\TastingReportData;
use App\Http\Controllers\Controller;
use App\Http\Requests\Cellar\StoreTastingReportRequest;
use App\Models\TastingReport;
use App\Models\User;
use App\Models\WineLot;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Cellar › Tastings: panel tasting reports. Each wine scored in a report is
 * also a tasting note on its lot (kept in step by SaveTastingReportAction).
 */
class TastingReportController extends Controller
{
    private const WITH = ['notes.wineLot', 'notes.vessel', 'createdBy'];

    public function index(): Response
    {
        return Inertia::render('Cellar/Tastings/Index', [
            'reports' => array_values(TastingReport::query()->with(self::WITH)->orderByDesc('date')->get()
                ->map(fn (TastingReport $r): array => TastingReportData::fromModel($r)->toArray())->all()),
            'lots' => fn (): array => $this->lots(),
        ]);
    }

    public function show(TastingReport $tastingReport): Response
    {
        return Inertia::render('Cellar/Tastings/Show', [
            'report' => TastingReportData::fromModel($tastingReport->load(self::WITH), withDetail: true)->toArray(),
            'lots' => fn (): array => $this->lots(),
        ]);
    }

    public function store(StoreTastingReportRequest $request, SaveTastingReportAction $action): RedirectResponse
    {
        $report = $action->execute(null, $request->validated(), $this->userId($request));

        return redirect('/cellar/tastings/'.$report->getKey())->with('success', __('Tasting report saved.'));
    }

    public function update(StoreTastingReportRequest $request, TastingReport $tastingReport, SaveTastingReportAction $action): RedirectResponse
    {
        $action->execute($tastingReport, $request->validated(), $this->userId($request));

        return back()->with('success', __('Tasting report saved.'));
    }

    public function destroy(TastingReport $tastingReport, DeleteTastingReportAction $action): RedirectResponse
    {
        $action->execute($tastingReport);

        return redirect('/cellar/tastings')->with('success', __('Tasting report deleted.'));
    }

    /**
     * Wines that can be tasted: every lot not yet blended away.
     *
     * @return list<array{value: string, label: string, description: string}>
     */
    private function lots(): array
    {
        return array_values(WineLot::query()
            ->where('status', '!=', 'BLENDED')
            ->orderByDesc('vintage')
            ->orderBy('name')
            ->get(['id', 'name', 'vintage', 'lot_number'])
            ->map(fn (WineLot $l): array => ['value' => (string) $l->getKey(), 'label' => trim($l->name.' '.$l->vintage), 'description' => $l->lot_number])
            ->all());
    }

    private function userId(Request $request): string
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return $user->getKey();
    }
}
