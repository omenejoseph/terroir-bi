<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\Cellar\AddCellarAdditionAction;
use App\Actions\Cellar\AddCellarAnalysisAction;
use App\Actions\Cellar\AddCellarProcessAction;
use App\Actions\Cellar\AddTastingNoteAction;
use App\Actions\Cellar\AdjustLotVolumeAction;
use App\Actions\Cellar\AssignFermentationTemplateAction;
use App\Actions\Cellar\AssignLotToVesselAction;
use App\Actions\Cellar\BulkAddAdditionsAction;
use App\Actions\Cellar\BulkAddAnalysesAction;
use App\Actions\Cellar\CreateBottlingAction;
use App\Actions\Cellar\CreateTransferAction;
use App\Actions\Cellar\CreateWineLotAction;
use App\Actions\Cellar\DeleteBottlingAction;
use App\Actions\Cellar\DeleteCellarAdditionAction;
use App\Actions\Cellar\DeleteCellarAnalysisAction;
use App\Actions\Cellar\DeleteCellarProcessAction;
use App\Actions\Cellar\DeleteTastingNoteAction;
use App\Actions\Cellar\DeleteTransferAction;
use App\Actions\Cellar\ExecuteBlendAction;
use App\Actions\Cellar\GenerateWorkOrdersFromProtocolAction;
use App\Actions\Cellar\UnassignLotFromVesselAction;
use App\Actions\Cellar\UpdateCellarAnalysisAction;
use App\Actions\Cellar\UpdateWineLotAction;
use App\DataTransferObjects\WineLotData;
use App\Http\Controllers\Controller;
use App\Http\Requests\Cellar\AdjustLotVolumeRequest;
use App\Http\Requests\Cellar\AssignProtocolRequest;
use App\Http\Requests\Cellar\AssignVesselRequest;
use App\Http\Requests\Cellar\BulkAdditionsRequest;
use App\Http\Requests\Cellar\BulkAnalysesRequest;
use App\Http\Requests\Cellar\ExecuteBlendRequest;
use App\Http\Requests\Cellar\StoreBottlingRequest;
use App\Http\Requests\Cellar\StoreCellarAdditionRequest;
use App\Http\Requests\Cellar\StoreCellarAnalysisRequest;
use App\Http\Requests\Cellar\StoreCellarProcessRequest;
use App\Http\Requests\Cellar\StoreTastingNoteRequest;
use App\Http\Requests\Cellar\StoreTransferRequest;
use App\Http\Requests\Cellar\StoreWineLotRequest;
use App\Http\Requests\Cellar\UpdateWineLotRequest;
use App\Models\Bottling;
use App\Models\CellarAddition;
use App\Models\CellarAnalysis;
use App\Models\CellarProcess;
use App\Models\CellarTastingNote;
use App\Models\CellarTransfer;
use App\Models\User;
use App\Models\Vessel;
use App\Models\VesselLot;
use App\Models\WineLot;
use App\Queries\ListWineLotsQuery;
use App\Queries\LotAnalysisTrendQuery;
use App\Services\Cellar\LotCostService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WineLotController extends Controller
{
    public function __construct(private readonly LotCostService $costs) {}

    public function index(Request $request, ListWineLotsQuery $query): JsonResponse
    {
        $perPage = max(1, min(200, (int) $request->query('per_page', '25')));
        $paginator = $query->paginate([
            'status' => $request->query('status'),
            'search' => $request->query('search'),
            'exclude_bottled' => $request->boolean('exclude_bottled'),
        ], $perPage);

        return response()->json([
            'data' => array_map(
                fn (WineLot $lot) => WineLotData::fromModel($lot)->toArray(),
                $paginator->items(),
            ),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    public function show(WineLot $wineLot): JsonResponse
    {
        return response()->json(['data' => $this->present($wineLot)]);
    }

    public function store(StoreWineLotRequest $request, CreateWineLotAction $action): JsonResponse
    {
        $lot = $action->execute($request->validated());

        return response()->json(['data' => $this->present($lot)], 201);
    }

    public function update(UpdateWineLotRequest $request, WineLot $wineLot, UpdateWineLotAction $action): JsonResponse
    {
        $lot = $action->execute($wineLot, $request->validated());

        return response()->json(['data' => $this->present($lot)]);
    }

    public function assignVessel(AssignVesselRequest $request, WineLot $wineLot, AssignLotToVesselAction $action): JsonResponse
    {
        $vessel = Vessel::query()->whereKey((string) $request->validated('vessel_id'))->firstOrFail();
        $action->execute($wineLot, $vessel, (string) $request->validated('volume'));

        return response()->json(['data' => $this->present($wineLot->refresh())]);
    }

    public function unassignVessel(WineLot $wineLot, VesselLot $vesselLot, UnassignLotFromVesselAction $action): JsonResponse
    {
        abort_unless($vesselLot->wine_lot_id === $wineLot->getKey(), 404);
        $action->execute($vesselLot);

        return response()->json(['data' => $this->present($wineLot->refresh())]);
    }

    public function adjustVolume(AdjustLotVolumeRequest $request, WineLot $wineLot, AdjustLotVolumeAction $action): JsonResponse
    {
        $vesselId = $request->validated('vessel_id');
        $lot = $action->execute(
            $wineLot,
            (string) $request->validated('delta'),
            $vesselId !== null ? (string) $vesselId : null,
        );

        return response()->json(['data' => $this->present($lot)]);
    }

    // --- Analyses -----------------------------------------------------------

    public function analysisTrend(WineLot $wineLot, LotAnalysisTrendQuery $query): JsonResponse
    {
        return response()->json(['data' => $query->get($wineLot)]);
    }

    public function addAnalysis(StoreCellarAnalysisRequest $request, WineLot $wineLot, AddCellarAnalysisAction $action): JsonResponse
    {
        $action->execute($wineLot, $request->validated(), $this->userId($request));

        return response()->json(['data' => $this->present($wineLot->refresh())], 201);
    }

    public function updateAnalysis(StoreCellarAnalysisRequest $request, WineLot $wineLot, CellarAnalysis $analysis, UpdateCellarAnalysisAction $action): JsonResponse
    {
        abort_unless($analysis->wine_lot_id === $wineLot->getKey(), 404);
        $action->execute($analysis, $request->validated());

        return response()->json(['data' => $this->present($wineLot->refresh())]);
    }

    public function deleteAnalysis(WineLot $wineLot, CellarAnalysis $analysis, DeleteCellarAnalysisAction $action): JsonResponse
    {
        abort_unless($analysis->wine_lot_id === $wineLot->getKey(), 404);
        $action->execute($analysis);

        return response()->json(['data' => $this->present($wineLot->refresh())]);
    }

    public function bulkAnalyses(BulkAnalysesRequest $request, BulkAddAnalysesAction $action): JsonResponse
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = (array) $request->validated('analyses', []);
        $created = $action->execute($rows, $this->userId($request));

        return response()->json(['data' => ['created' => count($created)]], 201);
    }

    public function bulkAdditions(BulkAdditionsRequest $request, BulkAddAdditionsAction $action): JsonResponse
    {
        $created = $action->execute($request->validated(), $this->userId($request));

        return response()->json(['data' => ['created' => $created]], 201);
    }

    public function blend(ExecuteBlendRequest $request, ExecuteBlendAction $action): JsonResponse
    {
        $lot = $action->execute($request->validated(), $this->userId($request));

        return response()->json(['data' => $this->present($lot)], 201);
    }

    // --- Additions / processes / tastings -----------------------------------

    public function addAddition(StoreCellarAdditionRequest $request, WineLot $wineLot, AddCellarAdditionAction $action): JsonResponse
    {
        $action->execute($wineLot, $request->validated(), $this->userId($request));

        return response()->json(['data' => $this->present($wineLot->refresh())], 201);
    }

    public function deleteAddition(WineLot $wineLot, CellarAddition $addition, DeleteCellarAdditionAction $action): JsonResponse
    {
        abort_unless($addition->wine_lot_id === $wineLot->getKey(), 404);
        $action->execute($addition);

        return response()->json(['data' => $this->present($wineLot->refresh())]);
    }

    public function addProcess(StoreCellarProcessRequest $request, WineLot $wineLot, AddCellarProcessAction $action): JsonResponse
    {
        $action->execute($wineLot, $request->validated(), $this->userId($request));

        return response()->json(['data' => $this->present($wineLot->refresh())], 201);
    }

    public function deleteProcess(WineLot $wineLot, CellarProcess $process, DeleteCellarProcessAction $action): JsonResponse
    {
        abort_unless($process->wine_lot_id === $wineLot->getKey(), 404);
        $action->execute($process);

        return response()->json(['data' => $this->present($wineLot->refresh())]);
    }

    public function addTasting(StoreTastingNoteRequest $request, WineLot $wineLot, AddTastingNoteAction $action): JsonResponse
    {
        $action->execute($wineLot, $request->validated(), $this->userId($request));

        return response()->json(['data' => $this->present($wineLot->refresh())], 201);
    }

    public function deleteTasting(WineLot $wineLot, CellarTastingNote $tastingNote, DeleteTastingNoteAction $action): JsonResponse
    {
        abort_unless($tastingNote->wine_lot_id === $wineLot->getKey(), 404);
        $action->execute($tastingNote);

        return response()->json(['data' => $this->present($wineLot->refresh())]);
    }

    // --- Transfers / bottling ----------------------------------------------

    public function addTransfer(StoreTransferRequest $request, WineLot $wineLot, CreateTransferAction $action): JsonResponse
    {
        $action->execute($wineLot, $request->validated(), $this->userId($request));

        return response()->json(['data' => $this->present($wineLot->refresh())], 201);
    }

    public function deleteTransfer(WineLot $wineLot, CellarTransfer $transfer, DeleteTransferAction $action): JsonResponse
    {
        abort_unless($transfer->from_lot_id === $wineLot->getKey() || $transfer->to_lot_id === $wineLot->getKey(), 404);
        $action->execute($transfer);

        return response()->json(['data' => $this->present($wineLot->refresh())]);
    }

    public function addBottling(StoreBottlingRequest $request, WineLot $wineLot, CreateBottlingAction $action): JsonResponse
    {
        $action->execute($wineLot, $request->validated(), $this->userId($request));

        return response()->json(['data' => $this->present($wineLot->refresh())], 201);
    }

    public function deleteBottling(WineLot $wineLot, Bottling $bottling, DeleteBottlingAction $action): JsonResponse
    {
        abort_unless($bottling->wine_lot_id === $wineLot->getKey(), 404);
        $action->execute($bottling);

        return response()->json(['data' => $this->present($wineLot->refresh())]);
    }

    // --- Fermentation protocol ---------------------------------------------

    public function assignProtocol(AssignProtocolRequest $request, WineLot $wineLot, AssignFermentationTemplateAction $action): JsonResponse
    {
        $templateId = $request->validated('fermentation_template_id');
        $action->execute($wineLot, $templateId !== null ? (string) $templateId : null);

        return response()->json(['data' => $this->present($wineLot->refresh())]);
    }

    public function generateProtocol(WineLot $wineLot, GenerateWorkOrdersFromProtocolAction $action, Request $request): JsonResponse
    {
        $result = $action->execute($wineLot, $this->userId($request));

        return response()->json(['data' => $result]);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(WineLot $lot): array
    {
        $lot->loadMissing([
            'grapes', 'vesselLots.vessel', 'analyses.vessel', 'additions',
            'processes', 'tastingNotes', 'transfersOut', 'transfersIn', 'bottlings',
        ]);

        return WineLotData::fromModel($lot, withDetail: true, costBreakdown: $this->costs->breakdown($lot))->toArray();
    }

    private function userId(Request $request): string
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return $user->getKey();
    }
}
