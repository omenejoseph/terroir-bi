<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\Cellar\BulkCreateVesselsAction;
use App\Actions\Cellar\CreateVesselAction;
use App\Actions\Cellar\DeleteVesselAction;
use App\Actions\Cellar\DuplicateVesselsAction;
use App\Actions\Cellar\RenameCellarRoomAction;
use App\Actions\Cellar\UpdateVesselAction;
use App\Actions\Cellar\UpdateVesselLayoutAction;
use App\DataTransferObjects\VesselData;
use App\Http\Controllers\Controller;
use App\Http\Requests\Cellar\BulkCreateVesselsRequest;
use App\Http\Requests\Cellar\DuplicateVesselsRequest;
use App\Http\Requests\Cellar\RenameRoomRequest;
use App\Http\Requests\Cellar\StoreVesselRequest;
use App\Http\Requests\Cellar\UpdateVesselLayoutRequest;
use App\Http\Requests\Cellar\UpdateVesselRequest;
use App\Models\Vessel;
use App\Queries\ListVesselsQuery;
use App\Queries\VesselDetailQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class VesselController extends Controller
{
    public function index(Request $request, ListVesselsQuery $query): JsonResponse
    {
        $vessels = $query->get([
            'room' => $request->query('room'),
            'active_only' => $request->boolean('active_only'),
        ]);

        return response()->json([
            'data' => $vessels->map(fn (Vessel $v) => VesselData::fromModel($v, withLots: true)->toArray())->all(),
        ]);
    }

    public function show(Vessel $vessel, VesselDetailQuery $detail): JsonResponse
    {
        $vessel->loadMissing('vesselLots.wineLot');

        return response()->json(['data' => [
            ...VesselData::fromModel($vessel, withLots: true)->toArray(),
            ...$detail->get($vessel),
        ]]);
    }

    public function duplicate(DuplicateVesselsRequest $request, DuplicateVesselsAction $action): JsonResponse
    {
        /** @var list<string> $ids */
        $ids = array_values((array) $request->validated('vessel_ids'));
        $created = $action->execute($ids, (int) $request->validated('count'));

        return response()->json([
            'data' => $created->map(fn (Vessel $v) => VesselData::fromModel($v)->toArray())->all(),
        ], 201);
    }

    public function store(StoreVesselRequest $request, CreateVesselAction $action): JsonResponse
    {
        $vessel = $action->execute($request->validated());

        return response()->json(['data' => VesselData::fromModel($vessel)->toArray()], 201);
    }

    public function update(UpdateVesselRequest $request, Vessel $vessel, UpdateVesselAction $action): JsonResponse
    {
        $vessel = $action->execute($vessel, $request->validated());

        return response()->json(['data' => VesselData::fromModel($vessel)->toArray()]);
    }

    public function layout(UpdateVesselLayoutRequest $request, UpdateVesselLayoutAction $action): JsonResponse
    {
        /** @var list<array<string, mixed>> $updates */
        $updates = (array) $request->validated('updates', []);
        $action->execute($updates);

        return response()->json(status: 204);
    }

    public function bulkCreate(BulkCreateVesselsRequest $request, BulkCreateVesselsAction $action): JsonResponse
    {
        $vessels = $action->execute($request->validated());

        return response()->json([
            'data' => $vessels->map(fn (Vessel $v) => VesselData::fromModel($v)->toArray())->all(),
        ], 201);
    }

    public function renameRoom(RenameRoomRequest $request, RenameCellarRoomAction $action): JsonResponse
    {
        $count = $action->execute((string) $request->validated('from'), (string) $request->validated('to'));

        return response()->json(['data' => ['updated' => $count]]);
    }

    public function destroy(Vessel $vessel, DeleteVesselAction $action): JsonResponse
    {
        $action->execute($vessel);

        return response()->json(status: 204);
    }
}
