<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\Cellar\AdjustLotVolumeAction;
use App\Actions\Cellar\AssignLotToVesselAction;
use App\Actions\Cellar\CreateWineLotAction;
use App\Actions\Cellar\UnassignLotFromVesselAction;
use App\Actions\Cellar\UpdateWineLotAction;
use App\DataTransferObjects\WineLotData;
use App\Http\Controllers\Controller;
use App\Http\Requests\Cellar\AdjustLotVolumeRequest;
use App\Http\Requests\Cellar\AssignVesselRequest;
use App\Http\Requests\Cellar\StoreWineLotRequest;
use App\Http\Requests\Cellar\UpdateWineLotRequest;
use App\Models\Vessel;
use App\Models\VesselLot;
use App\Models\WineLot;
use App\Queries\ListWineLotsQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WineLotController extends Controller
{
    public function index(Request $request, ListWineLotsQuery $query): JsonResponse
    {
        $paginator = $query->paginate([
            'status' => $request->query('status'),
            'search' => $request->query('search'),
            'exclude_bottled' => $request->boolean('exclude_bottled'),
        ]);

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

    /**
     * @return array<string, mixed>
     */
    private function present(WineLot $lot): array
    {
        $lot->loadMissing(['grapes', 'vesselLots.vessel']);

        return WineLotData::fromModel($lot, withDetail: true)->toArray();
    }
}
