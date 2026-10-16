<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\Cellar;

use App\Actions\Cellar\AddCellarAdditionAction;
use App\Actions\Cellar\AddCellarAnalysisAction;
use App\Actions\Cellar\AddCellarProcessAction;
use App\Actions\Cellar\AddTastingNoteAction;
use App\Actions\Cellar\AdjustLotVolumeAction;
use App\Actions\Cellar\AssignFermentationTemplateAction;
use App\Actions\Cellar\AssignLotToVesselAction;
use App\Actions\Cellar\BlendIntoLotAction;
use App\Actions\Cellar\BulkAssignLotToVesselsAction;
use App\Actions\Cellar\CreateBottlingAction;
use App\Actions\Cellar\CreateTransferAction;
use App\Actions\Cellar\CreateWineLotAction;
use App\Actions\Cellar\DeleteBottlingAction;
use App\Actions\Cellar\DeleteCellarAdditionAction;
use App\Actions\Cellar\DeleteCellarAnalysisAction;
use App\Actions\Cellar\DeleteCellarProcessAction;
use App\Actions\Cellar\DeleteTastingNoteAction;
use App\Actions\Cellar\DeleteTransferAction;
use App\Actions\Cellar\DeleteWineLotAction;
use App\Actions\Cellar\GenerateWorkOrdersFromProtocolAction;
use App\Actions\Cellar\UnassignLotFromVesselAction;
use App\Actions\Cellar\UpdateCellarAdditionAction;
use App\Actions\Cellar\UpdateCellarAnalysisAction;
use App\Actions\Cellar\UpdateTastingNoteAction;
use App\Actions\Cellar\UpdateWineLotAction;
use App\Authorization\MembershipContext;
use App\DataTransferObjects\CellarActivityData;
use App\DataTransferObjects\FermentationTemplateData;
use App\DataTransferObjects\WineLotData;
use App\Enums\CellarActivityStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Cellar\AdjustLotVolumeRequest;
use App\Http\Requests\Cellar\AssignProtocolRequest;
use App\Http\Requests\Cellar\AssignVesselRequest;
use App\Http\Requests\Cellar\BlendIntoLotRequest;
use App\Http\Requests\Cellar\BulkAssignLotToVesselsRequest;
use App\Http\Requests\Cellar\StoreBottlingRequest;
use App\Http\Requests\Cellar\StoreCellarAdditionRequest;
use App\Http\Requests\Cellar\StoreCellarAnalysisRequest;
use App\Http\Requests\Cellar\StoreCellarProcessRequest;
use App\Http\Requests\Cellar\StoreTastingNoteRequest;
use App\Http\Requests\Cellar\StoreTransferRequest;
use App\Http\Requests\Cellar\StoreWineLotRequest;
use App\Http\Requests\Cellar\UpdateCellarAdditionRequest;
use App\Http\Requests\Cellar\UpdateTastingNoteRequest;
use App\Http\Requests\Cellar\UpdateWineLotRequest;
use App\Models\Bottling;
use App\Models\CellarActivity;
use App\Models\CellarAddition;
use App\Models\CellarAnalysis;
use App\Models\CellarProcess;
use App\Models\CellarTastingNote;
use App\Models\CellarTransfer;
use App\Models\FermentationTemplate;
use App\Models\User;
use App\Models\Vessel;
use App\Models\VesselLot;
use App\Models\WineLot;
use App\Queries\CellarAttentionQuery;
use App\Queries\CellarHistoryQuery;
use App\Queries\CellarPickerOptions;
use App\Queries\ListWineLotsQuery;
use App\Queries\LotAnalysisTrendQuery;
use App\Queries\LotBarrelsQuery;
use App\Queries\LotTablesQuery;
use App\Queries\TraceabilityQuery;
use App\Services\Cellar\CellarClock;
use App\Services\Cellar\LotCostService;
use App\Services\Cellar\UndoStash;
use App\Support\LotScope;
use App\Support\PerPage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Inertia counterpart of Api\WineLotController: the lots list and a lot's
 * page, and every write made from them. Same Actions as the API.
 *
 * Money (grape price, cost breakdown, addition costs) is only sent to members
 * holding finance.view — the prototype showed it to admins only.
 */
class WineLotController extends Controller
{
    public function __construct(
        private readonly LotCostService $costs,
        private readonly MembershipContext $membership,
    ) {}

    public function index(Request $request, ListWineLotsQuery $query, CellarPickerOptions $options): Response
    {
        $filters = [
            'status' => $request->string('status')->toString() ?: null,
            'search' => $request->string('search')->toString() ?: null,
            'vintage' => $request->string('vintage')->toString() ?: null,
            'wine_type' => $request->string('wine_type')->toString() ?: null,
        ];

        $matching = $query->build(['status' => $filters['status'], 'search' => $filters['search']])
            ->when($filters['vintage'] !== null, fn ($q) => $q->where('vintage', $filters['vintage']))
            ->when($filters['wine_type'] !== null, fn ($q) => $q->where('wine_type', $filters['wine_type']));

        // The header's count and litres cover every lot the filters match, not just this page of them.
        $totals = ['count' => (clone $matching)->count(), 'volume' => number_format((float) (clone $matching)->sum('current_volume'), 3, '.', '')];

        $lots = $matching
            ->with(['grapes', 'latestAnalysis', 'latestAddition'])
            ->orderByDesc('vintage')
            ->orderBy('name')
            ->paginate(PerPage::fromRequest($request));

        return Inertia::render('Cellar/Lots/Index', [
            'lots' => [
                'data' => array_values($lots->getCollection()->map(fn (WineLot $l): array => $this->strip(WineLotData::fromModel($l)->toArray()))->all()),
                'meta' => ['current_page' => $lots->currentPage(), 'last_page' => $lots->lastPage(), 'per_page' => $lots->perPage(), 'total' => $lots->total()],
            ],
            'totals' => $totals,
            'filters' => $filters,
            'vintages' => array_values(WineLot::query()->whereNotNull('vintage')->distinct()->orderByDesc('vintage')->pluck('vintage')->map(fn ($v): string => (string) $v)->all()),
            'vessels' => fn (): array => $options->vessels(),
        ]);
    }

    /** The journey's filter chips and the record kinds each one covers. */
    private const JOURNEY_KINDS = [
        'analysis' => ['analysis'],
        'addition' => ['addition'],
        'move' => ['transfer', 'transfer_in', 'vessel'],
        'process' => ['process'],
        'tasting' => ['tasting'],
        'bottling' => ['bottling'],
        'planned' => ['planned_done', 'planned_skipped'],
    ];

    public function show(Request $request, WineLot $wineLot, LotTablesQuery $tables, LotBarrelsQuery $barrels, LotAnalysisTrendQuery $trend, CellarHistoryQuery $history, TraceabilityQuery $trace, CellarPickerOptions $options, CellarClock $clock, CellarAttentionQuery $attention): Response
    {
        // What part of the lot is on screen: the whole lot or one barrel (?vessel=), and which tab.
        $vessel = LotScope::resolve($wineLot, $request->query('vessel') !== null ? (string) $request->query('vessel') : null);
        $tab = in_array($request->query('tab'), ['overview', 'barrels', 'analysis', 'additions', 'tasting', 'transfers', 'processes', 'details'], true)
            ? (string) $request->query('tab')
            : 'overview';
        $perPage = PerPage::fromRequest($request);
        $page = max(1, (int) $request->query('page', 1));
        $kind = (string) $request->query('kind', '');

        // In a barrel's view the barrels table is that barrel (and its "whole lot" row is just it), not all of them.
        // Built once, and only if a prop below actually asks for it.
        $overview = null;
        $barrelData = function () use (&$overview, $barrels, $wineLot, $vessel): array {
            return $overview ??= $barrels->get($wineLot, $vessel);
        };

        // Every prop is a closure: a tab, barrel or page change is a partial visit that names only the
        // props it needs (scope, table, counts), so the lot, the trend and the barrels are not rebuilt
        // for it. A full visit evaluates them all.
        return Inertia::render('Cellar/Lots/Show', [
            'lot' => fn (): array => $this->present($wineLot),
            'scope' => function () use ($vessel, $tab, $kind, $barrelData): array {
                $scopeRow = $vessel === null ? null : collect($barrelData()['rows'])->firstWhere('vessel_id', $vessel);

                return ['vessel_id' => $vessel, 'vessel' => $scopeRow, 'tab' => $tab, 'kind' => $kind !== '' && isset(self::JOURNEY_KINDS[$kind]) ? $kind : null];
            },
            // The tab's own table, one page of it. Only the active tab's rows are read.
            'table' => fn (): ?array => match ($tab) {
                'overview' => $tables->journey($wineLot, $vessel, self::JOURNEY_KINDS[$kind] ?? null, $perPage, $page),
                'barrels' => $tables->slice($barrelData()['rows'], $perPage, $page),
                'analysis' => $tables->analyses($wineLot, $vessel, $perPage, $page),
                'additions' => $tables->additions($wineLot, $vessel, $perPage, $page),
                'tasting' => $tables->tastings($wineLot, $vessel, $perPage, $page),
                'transfers' => $tables->transfers($wineLot, $vessel, $perPage, $page),
                'processes' => $tables->processes($wineLot, $vessel, $perPage, $page),
                default => null,
            },
            // Bottlings sit under the Transfers tab (a bottling is where wine leaves the lot).
            'bottlings' => fn (): ?array => $tab === 'transfers' ? $tables->bottlings($wineLot, 10, 1) : null,
            'counts' => fn (): array => [...$tables->counts($wineLot, $vessel), 'barrels' => count($barrelData()['rows'])],
            // The barrels at a glance (the Barrels tab pages through all of them) and the combined figures.
            'barrels' => fn (): array => ['rows' => array_slice($barrelData()['rows'], 0, 8), 'combined' => $barrelData()['combined']],
            // The lot's next planned task (overdue ones first), for the workspace's "Next" tile.
            'nextPlanned' => function () use ($wineLot, $clock): ?array {
                $next = CellarActivity::query()->with(['vessel', 'round'])
                    ->where('wine_lot_id', $wineLot->getKey())
                    ->where('status', CellarActivityStatus::Planned->value)
                    ->orderBy('planned_for')->orderBy('sort_order')
                    ->first();

                return $next !== null ? CellarActivityData::fromModel($next, $clock->today())->toArray() : null;
            },
            'attention' => fn (): array => $attention->get((string) $wineLot->getKey()),
            // Every reading, oldest first, tagged by barrel: the charts and the stat tiles read it.
            'trend' => fn (): array => $trend->get($wineLot),
            'blendSources' => fn (): array => $this->blendSources($wineLot),
            'vessels' => fn (): array => $options->vessels(),
            'lots' => fn (): array => $options->lots(),
            'products' => fn (): array => $options->products(),
            'templates' => fn (): array => array_values(FermentationTemplate::query()->with('additives')->orderBy('name')->get()
                ->map(fn (FermentationTemplate $t): array => FermentationTemplateData::fromModel($t)->toArray())->all()),
            'inventoryItems' => Inertia::optional(fn (): array => $options->finishedItems()),
            'history' => Inertia::optional(fn (): array => $history->forLot($wineLot)),
            'trace' => Inertia::optional(fn (): array => $trace->get($wineLot)),
        ]);
    }

    public function store(StoreWineLotRequest $request, CreateWineLotAction $action): RedirectResponse
    {
        $lot = $action->execute($request->validated());

        return redirect('/cellar/lots/'.$lot->getKey())->with('success', __('Wine lot created.'));
    }

    public function update(UpdateWineLotRequest $request, WineLot $wineLot, UpdateWineLotAction $action): RedirectResponse
    {
        $action->execute($wineLot, $request->validated());

        return back()->with('success', __('Wine lot saved.'));
    }

    /**
     * What deleting a lot takes with it — the delete sheet names it before
     * anyone types the lot number.
     */
    public function deleteImpact(WineLot $wineLot): JsonResponse
    {
        $wineLot->loadMissing('vesselLots.vessel', 'bottlings');

        return response()->json([
            'lot_number' => $wineLot->lot_number,
            'vessels' => $wineLot->vesselLots->map(fn ($vl): string => (string) $vl->vessel?->name)->filter()->values()->all(),
            'bottlings' => $wineLot->bottlings->count(),
            'bottles' => (int) $wineLot->bottlings->sum('bottle_count'),
            'additions' => $wineLot->additions()->count(),
            'analyses' => $wineLot->analyses()->count(),
            'tastings' => $wineLot->tastingNotes()->count(),
            'processes' => $wineLot->processes()->count(),
            'transfers' => CellarTransfer::query()->where('from_lot_id', $wineLot->getKey())->orWhere('to_lot_id', $wineLot->getKey())->count(),
        ]);
    }

    public function destroy(WineLot $wineLot, DeleteWineLotAction $action): RedirectResponse
    {
        $action->execute($wineLot);

        return redirect('/cellar')->with('success', __('Wine lot deleted.'));
    }

    public function assignVessel(AssignVesselRequest $request, WineLot $wineLot, AssignLotToVesselAction $action): RedirectResponse
    {
        $vessel = Vessel::query()->whereKey((string) $request->validated('vessel_id'))->firstOrFail();
        $action->execute($wineLot, $vessel, (string) $request->validated('volume'));

        return back()->with('success', __('Wine placed in :vessel.', ['vessel' => $vessel->name]));
    }

    public function unassignVessel(WineLot $wineLot, VesselLot $vesselLot, UnassignLotFromVesselAction $action): RedirectResponse
    {
        abort_unless($vesselLot->wine_lot_id === $wineLot->getKey(), 404);
        $action->execute($vesselLot);

        return back()->with('success', __('Wine removed from the vessel.'));
    }

    /** The map's "Assign lot" panel; `dry_run` answers with the preview as JSON. */
    public function bulkAssignVessels(BulkAssignLotToVesselsRequest $request, WineLot $wineLot, BulkAssignLotToVesselsAction $action): RedirectResponse|JsonResponse
    {
        /** @var array{vessel_ids: list<string>, mode: string, source: string, volume?: float|int|string|null, dry_run?: bool} $data */
        $data = $request->validated();
        $plan = $action->execute($wineLot, $data);

        if (! empty($data['dry_run'])) {
            return response()->json(['data' => $plan]);
        }

        return back()->with('success', __(':lot assigned to :n vessels.', ['lot' => $wineLot->name, 'n' => count($data['vessel_ids'])]));
    }

    public function adjustVolume(AdjustLotVolumeRequest $request, WineLot $wineLot, AdjustLotVolumeAction $action): RedirectResponse
    {
        $vesselId = $request->validated('vessel_id');
        $reason = $request->validated('reason');
        $action->execute(
            $wineLot,
            (string) $request->validated('delta'),
            $vesselId !== null ? (string) $vesselId : null,
            $reason !== null ? (string) $reason : null,
            $this->userId($request),
        );

        return back()->with('success', __('Volume adjusted.'));
    }

    public function blendIn(BlendIntoLotRequest $request, WineLot $wineLot, BlendIntoLotAction $action): RedirectResponse
    {
        /** @var array{from_vessel_id: string, volume: float|int|string, to_vessel_id: string, note?: string|null} $data */
        $data = $request->validated();
        $action->execute($wineLot, $data, $this->userId($request));

        return back()->with('success', __('Wine blended in.'));
    }

    public function addAnalysis(StoreCellarAnalysisRequest $request, WineLot $wineLot, AddCellarAnalysisAction $action): RedirectResponse
    {
        $action->execute($wineLot, $request->validated(), $this->userId($request));

        return back()->with('success', __('Analysis saved.'));
    }

    public function updateAnalysis(StoreCellarAnalysisRequest $request, WineLot $wineLot, CellarAnalysis $analysis, UpdateCellarAnalysisAction $action): RedirectResponse
    {
        abort_unless($analysis->wine_lot_id === $wineLot->getKey(), 404);
        $action->execute($analysis, $request->validated());

        return back()->with('success', __('Analysis saved.'));
    }

    public function deleteAnalysis(Request $request, WineLot $wineLot, CellarAnalysis $analysis, DeleteCellarAnalysisAction $action, UndoStash $undo): RedirectResponse
    {
        abort_unless($analysis->wine_lot_id === $wineLot->getKey(), 404);
        $token = $undo->stash($analysis, $this->userId($request));
        $action->execute($analysis);

        return back()->with('success', __('Analysis deleted.'))->with('undo', $token);
    }

    public function addAddition(StoreCellarAdditionRequest $request, WineLot $wineLot, AddCellarAdditionAction $action): RedirectResponse
    {
        $action->execute($wineLot, $request->validated(), $this->userId($request));

        return back()->with('success', __('Addition saved.'));
    }

    public function updateAddition(UpdateCellarAdditionRequest $request, WineLot $wineLot, CellarAddition $addition, UpdateCellarAdditionAction $action): RedirectResponse
    {
        abort_unless($addition->wine_lot_id === $wineLot->getKey(), 404);
        $action->execute($addition, $request->validated());

        return back()->with('success', __('Addition saved.'));
    }

    public function deleteAddition(Request $request, WineLot $wineLot, CellarAddition $addition, DeleteCellarAdditionAction $action, UndoStash $undo): RedirectResponse
    {
        abort_unless($addition->wine_lot_id === $wineLot->getKey(), 404);
        $token = $undo->stash($addition, $this->userId($request));
        $action->execute($addition);

        return back()->with('success', __('Addition deleted — stock restored.'))->with('undo', $token);
    }

    public function addProcess(StoreCellarProcessRequest $request, WineLot $wineLot, AddCellarProcessAction $action): RedirectResponse
    {
        $action->execute($wineLot, $request->validated(), $this->userId($request));

        return back()->with('success', __('Process saved.'));
    }

    public function deleteProcess(Request $request, WineLot $wineLot, CellarProcess $process, DeleteCellarProcessAction $action, UndoStash $undo): RedirectResponse
    {
        abort_unless($process->wine_lot_id === $wineLot->getKey(), 404);
        $token = $undo->stash($process, $this->userId($request));
        $action->execute($process);

        return back()->with('success', __('Process deleted.'))->with('undo', $token);
    }

    public function addTasting(StoreTastingNoteRequest $request, WineLot $wineLot, AddTastingNoteAction $action): RedirectResponse
    {
        $action->execute($wineLot, $request->validated(), $this->userId($request));

        return back()->with('success', __('Tasting note saved.'));
    }

    public function updateTasting(UpdateTastingNoteRequest $request, WineLot $wineLot, CellarTastingNote $tastingNote, UpdateTastingNoteAction $action): RedirectResponse
    {
        abort_unless($tastingNote->wine_lot_id === $wineLot->getKey(), 404);
        $action->execute($tastingNote, $request->validated());

        return back()->with('success', __('Tasting note saved.'));
    }

    public function deleteTasting(Request $request, WineLot $wineLot, CellarTastingNote $tastingNote, DeleteTastingNoteAction $action, UndoStash $undo): RedirectResponse
    {
        abort_unless($tastingNote->wine_lot_id === $wineLot->getKey(), 404);
        $token = $undo->stash($tastingNote, $this->userId($request));
        $action->execute($tastingNote);

        return back()->with('success', __('Tasting note deleted.'))->with('undo', $token);
    }

    public function addTransfer(StoreTransferRequest $request, WineLot $wineLot, CreateTransferAction $action): RedirectResponse
    {
        $action->execute($wineLot, $request->validated(), $this->userId($request));

        return back()->with('success', __('Transfer saved.'));
    }

    public function deleteTransfer(Request $request, WineLot $wineLot, CellarTransfer $transfer, DeleteTransferAction $action, UndoStash $undo): RedirectResponse
    {
        abort_unless($transfer->from_lot_id === $wineLot->getKey() || $transfer->to_lot_id === $wineLot->getKey(), 404);
        $token = $undo->stash($transfer, $this->userId($request));
        $action->execute($transfer);

        return back()->with('success', __('Transfer reversed.'))->with('undo', $token);
    }

    public function addBottling(StoreBottlingRequest $request, WineLot $wineLot, CreateBottlingAction $action): RedirectResponse
    {
        $action->execute($wineLot, $request->validated(), $this->userId($request));

        return back()->with('success', __('Bottling saved.'));
    }

    public function deleteBottling(WineLot $wineLot, Bottling $bottling, DeleteBottlingAction $action): RedirectResponse
    {
        abort_unless($bottling->wine_lot_id === $wineLot->getKey(), 404);
        $action->execute($bottling);

        return back()->with('success', __('Bottling reversed — wine is back in its vessels.'));
    }

    public function assignProtocol(AssignProtocolRequest $request, WineLot $wineLot, AssignFermentationTemplateAction $action): RedirectResponse
    {
        $templateId = $request->validated('fermentation_template_id');
        $action->execute($wineLot, $templateId !== null ? (string) $templateId : null);

        return back()->with('success', __('Protocol saved.'));
    }

    public function generateProtocol(Request $request, WineLot $wineLot, GenerateWorkOrdersFromProtocolAction $action): RedirectResponse
    {
        $result = $action->execute($wineLot, $this->userId($request));

        return back()->with('success', __("Generated :n of today's tasks.", ['n' => $result['created']]));
    }

    /**
     * @return array<string, mixed>
     */
    private function present(WineLot $lot): array
    {
        // The lot page pages its child tables itself (LotTablesQuery), so it loads only what it shows whole.
        $lot->loadMissing(['grapes', 'vesselLots.vessel', 'plannedAdditions.vessel']);

        $finance = $this->membership->can('finance.view');
        $data = WineLotData::fromModel($lot, withDetail: true, costBreakdown: $finance ? $this->costs->breakdown($lot) : null)->toArray();

        return $this->strip($data);
    }

    /**
     * Money out of a lot payload for members without finance.view.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function strip(array $data): array
    {
        if ($this->membership->can('finance.view')) {
            return $data;
        }

        $data['grape_cost'] = null;
        $data['grape_price_per_kg'] = null;
        $data['carried_cost'] = null;
        $data['cost_breakdown'] = null;
        foreach (['grapes' => 'price_per_kg', 'additions' => 'total_cost'] as $list => $field) {
            if (isset($data[$list]) && is_array($data[$list])) {
                foreach ($data[$list] as $i => $row) {
                    if (is_array($row)) {
                        $data[$list][$i][$field] = null;
                    }
                }
            }
        }

        return $data;
    }

    /**
     * Where a blended lot came from: each source lot and the litres it gave.
     *
     * @return list<array{lot_id: string, name: string, lot_number: string, liters: string}>
     */
    private function blendSources(WineLot $lot): array
    {
        $sources = [];
        $transfers = CellarTransfer::query()
            ->with('fromLot')
            ->where('to_lot_id', $lot->getKey())
            ->whereColumn('from_lot_id', '!=', 'to_lot_id')
            ->get();
        foreach ($transfers as $t) {
            $id = (string) $t->from_lot_id;
            $sources[$id] ??= ['lot_id' => $id, 'name' => (string) $t->fromLot?->name, 'lot_number' => (string) $t->fromLot?->lot_number, 'liters' => 0.0];
            $sources[$id]['liters'] += (float) $t->volume_liters;
        }

        return array_values(array_map(
            fn (array $s): array => [...$s, 'liters' => number_format($s['liters'], 3, '.', '')],
            $sources,
        ));
    }

    private function userId(Request $request): string
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return $user->getKey();
    }
}
