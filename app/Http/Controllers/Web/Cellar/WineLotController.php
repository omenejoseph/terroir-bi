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
use App\DataTransferObjects\FermentationTemplateData;
use App\DataTransferObjects\WineLotData;
use App\Enums\InventoryCategory;
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
use App\Models\CellarAddition;
use App\Models\CellarAnalysis;
use App\Models\CellarProcess;
use App\Models\CellarTastingNote;
use App\Models\CellarTransfer;
use App\Models\FermentationTemplate;
use App\Models\InventoryItem;
use App\Models\User;
use App\Models\Vessel;
use App\Models\VesselLot;
use App\Models\WineLot;
use App\Queries\CellarHistoryQuery;
use App\Queries\CellarPickerOptions;
use App\Queries\ListWineLotsQuery;
use App\Queries\LotAnalysisTrendQuery;
use App\Queries\LotTimelineQuery;
use App\Queries\TraceabilityQuery;
use App\Services\Cellar\LotCostService;
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

        $lots = $query->build(['status' => $filters['status'], 'search' => $filters['search']])
            ->with(['grapes', 'latestAnalysis', 'latestAddition'])
            ->when($filters['vintage'] !== null, fn ($q) => $q->where('vintage', $filters['vintage']))
            ->when($filters['wine_type'] !== null, fn ($q) => $q->where('wine_type', $filters['wine_type']))
            ->orderByDesc('vintage')
            ->orderBy('name')
            ->get();

        return Inertia::render('Cellar/Lots/Index', [
            'lots' => array_values($lots->map(fn (WineLot $l): array => $this->strip(WineLotData::fromModel($l)->toArray()))->all()),
            'filters' => $filters,
            'vintages' => array_values(WineLot::query()->whereNotNull('vintage')->distinct()->orderByDesc('vintage')->pluck('vintage')->map(fn ($v): string => (string) $v)->all()),
            'vessels' => fn (): array => $options->vessels(),
        ]);
    }

    public function show(WineLot $wineLot, LotTimelineQuery $timeline, LotAnalysisTrendQuery $trend, CellarHistoryQuery $history, TraceabilityQuery $trace, CellarPickerOptions $options): Response
    {
        return Inertia::render('Cellar/Lots/Show', [
            'lot' => $this->present($wineLot),
            'timeline' => $timeline->get($wineLot),
            'trend' => $trend->get($wineLot),
            'blendSources' => $this->blendSources($wineLot),
            'vessels' => fn (): array => $options->vessels(),
            'lots' => fn (): array => $options->lots(),
            'products' => fn (): array => $options->products(),
            'templates' => fn (): array => array_values(FermentationTemplate::query()->with('additives')->orderBy('name')->get()
                ->map(fn (FermentationTemplate $t): array => FermentationTemplateData::fromModel($t)->toArray())->all()),
            'inventoryItems' => Inertia::optional(fn (): array => $this->finishedItems()),
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

    public function deleteAnalysis(WineLot $wineLot, CellarAnalysis $analysis, DeleteCellarAnalysisAction $action): RedirectResponse
    {
        abort_unless($analysis->wine_lot_id === $wineLot->getKey(), 404);
        $action->execute($analysis);

        return back()->with('success', __('Analysis deleted.'));
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

    public function deleteAddition(WineLot $wineLot, CellarAddition $addition, DeleteCellarAdditionAction $action): RedirectResponse
    {
        abort_unless($addition->wine_lot_id === $wineLot->getKey(), 404);
        $action->execute($addition);

        return back()->with('success', __('Addition deleted — stock restored.'));
    }

    public function addProcess(StoreCellarProcessRequest $request, WineLot $wineLot, AddCellarProcessAction $action): RedirectResponse
    {
        $action->execute($wineLot, $request->validated(), $this->userId($request));

        return back()->with('success', __('Process saved.'));
    }

    public function deleteProcess(WineLot $wineLot, CellarProcess $process, DeleteCellarProcessAction $action): RedirectResponse
    {
        abort_unless($process->wine_lot_id === $wineLot->getKey(), 404);
        $action->execute($process);

        return back()->with('success', __('Process deleted.'));
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

    public function deleteTasting(WineLot $wineLot, CellarTastingNote $tastingNote, DeleteTastingNoteAction $action): RedirectResponse
    {
        abort_unless($tastingNote->wine_lot_id === $wineLot->getKey(), 404);
        $action->execute($tastingNote);

        return back()->with('success', __('Tasting note deleted.'));
    }

    public function addTransfer(StoreTransferRequest $request, WineLot $wineLot, CreateTransferAction $action): RedirectResponse
    {
        $action->execute($wineLot, $request->validated(), $this->userId($request));

        return back()->with('success', __('Transfer saved.'));
    }

    public function deleteTransfer(WineLot $wineLot, CellarTransfer $transfer, DeleteTransferAction $action): RedirectResponse
    {
        abort_unless($transfer->from_lot_id === $wineLot->getKey() || $transfer->to_lot_id === $wineLot->getKey(), 404);
        $action->execute($transfer);

        return back()->with('success', __('Transfer reversed.'));
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
        $lot->loadMissing([
            'grapes', 'vesselLots.vessel', 'analyses.vessel', 'additions.vessel',
            'processes.vessel', 'tastingNotes', 'transfersOut.toLot', 'transfersOut.fromVessel', 'transfersOut.toVessel',
            'transfersIn.fromLot', 'transfersIn.fromVessel', 'transfersIn.toVessel', 'bottlings.inventoryItem', 'plannedAdditions.vessel',
        ]);

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

    /**
     * Finished-goods items a bottling can feed.
     *
     * @return list<array{value: string, label: string, description: string|null}>
     */
    private function finishedItems(): array
    {
        return array_values(InventoryItem::query()
            ->where('category', InventoryCategory::Finished->value)
            ->orderBy('name')
            ->get(['id', 'name', 'sku'])
            ->map(fn (InventoryItem $i): array => ['value' => (string) $i->getKey(), 'label' => $i->name, 'description' => $i->sku])
            ->all());
    }

    private function userId(Request $request): string
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return $user->getKey();
    }
}
