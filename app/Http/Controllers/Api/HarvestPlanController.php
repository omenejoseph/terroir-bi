<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Vineyards\RecordIntakeRequest;
use App\Http\Requests\Vineyards\StoreHarvestEntryRequest;
use App\Http\Requests\Vineyards\StoreHarvestPlanRequest;
use App\Models\HarvestEntry;
use App\Models\HarvestPlan;
use App\Models\User;
use App\Services\Vineyards\HarvestIntakeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class HarvestPlanController extends Controller
{
    public function __construct(private readonly HarvestIntakeService $intake) {}

    public function index(): JsonResponse
    {
        $plans = HarvestPlan::query()->withCount('entries')->orderByDesc('season')->get();

        return response()->json(['data' => $plans->map(fn (HarvestPlan $p) => $this->presentPlan($p))->all()]);
    }

    public function show(HarvestPlan $harvestPlan): JsonResponse
    {
        return response()->json(['data' => $this->presentPlan($harvestPlan, true)]);
    }

    public function store(StoreHarvestPlanRequest $request): JsonResponse
    {
        $plan = HarvestPlan::create($request->validated() + ['created_by_id' => $this->userId($request)]);

        return response()->json(['data' => $this->presentPlan($plan)], 201);
    }

    public function update(StoreHarvestPlanRequest $request, HarvestPlan $harvestPlan): JsonResponse
    {
        $harvestPlan->update($request->validated());

        return response()->json(['data' => $this->presentPlan($harvestPlan)]);
    }

    public function destroy(HarvestPlan $harvestPlan): JsonResponse
    {
        $harvestPlan->delete();

        return response()->json(status: 204);
    }

    public function addEntry(StoreHarvestEntryRequest $request, HarvestPlan $harvestPlan): JsonResponse
    {
        $harvestPlan->entries()->create($request->validated());

        return response()->json(['data' => $this->presentPlan($harvestPlan->refresh(), true)], 201);
    }

    public function removeEntry(HarvestPlan $harvestPlan, HarvestEntry $harvestEntry): JsonResponse
    {
        abort_unless($harvestEntry->harvest_plan_id === $harvestPlan->getKey(), 404);
        if ($harvestEntry->status->value !== 'PLANNED') {
            throw ValidationException::withMessages(['entry' => 'Only a planned entry can be removed.']);
        }
        $harvestEntry->delete();

        return response()->json(['data' => $this->presentPlan($harvestPlan->refresh(), true)]);
    }

    public function recordIntake(RecordIntakeRequest $request, HarvestPlan $harvestPlan, HarvestEntry $harvestEntry): JsonResponse
    {
        abort_unless($harvestEntry->harvest_plan_id === $harvestPlan->getKey(), 404);
        $this->intake->record($harvestEntry, $request->validated(), $this->userId($request));

        return response()->json(['data' => $this->presentPlan($harvestPlan->refresh(), true)]);
    }

    /**
     * @return array<string, mixed>
     */
    private function presentPlan(HarvestPlan $p, bool $detail = false): array
    {
        $payload = [
            'id' => $p->getKey(),
            'name' => $p->name,
            'season' => $p->season,
            'status' => $p->status->value,
            'yield_ratio' => (string) $p->yield_ratio,
            'notes' => $p->notes,
            'entries_count' => (int) ($p->getAttribute('entries_count') ?? $p->entries()->count()),
        ];

        if ($detail) {
            $p->loadMissing(['entries.parcel']);
            $payload['entries'] = $p->entries->map(fn (HarvestEntry $e) => [
                'id' => $e->getKey(),
                'status' => $e->status->value,
                'source' => $e->source->value,
                'grape_variety' => $e->grape_variety ?? $e->parcel?->grape_variety,
                'parcel_id' => $e->parcel_id,
                'parcel_name' => $e->parcel?->name,
                'contract_id' => $e->contract_id,
                'planned_vessel_id' => $e->planned_vessel_id,
                'wine_lot_id' => $e->wine_lot_id,
                'planned_date' => $e->planned_date?->toIso8601String(),
                'estimated_yield_kg' => $e->estimated_yield_kg,
                'actual_date' => $e->actual_date?->toIso8601String(),
                'actual_yield_kg' => $e->actual_yield_kg,
                'actual_volume_liters' => $e->actual_volume_liters,
                'brix' => $e->brix,
                'ph' => $e->ph,
            ])->all();
        }

        return $payload;
    }

    private function userId(Request $request): string
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return $user->getKey();
    }
}
