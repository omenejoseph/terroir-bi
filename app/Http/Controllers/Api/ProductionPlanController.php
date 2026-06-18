<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Enums\ProductionPlanStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Production\ConfirmPlanRequest;
use App\Http\Requests\Production\StoreProductionPlanRequest;
use App\Http\Requests\Production\UpdateProductionPlanRequest;
use App\Models\ProductionPlan;
use App\Models\ProductionPlanRow;
use App\Models\User;
use App\Services\Production\PlanConfirmationService;
use App\Services\Production\ProductionCalculator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ProductionPlanController extends Controller
{
    public function __construct(
        private readonly ProductionCalculator $calculator,
        private readonly PlanConfirmationService $confirmation,
    ) {}

    public function index(): JsonResponse
    {
        $plans = ProductionPlan::query()->withCount('rows')->orderByDesc('created_at')->get();

        return response()->json(['data' => $plans->map(fn (ProductionPlan $p) => $this->present($p))->all()]);
    }

    public function show(ProductionPlan $productionPlan): JsonResponse
    {
        return response()->json(['data' => $this->present($productionPlan, true)]);
    }

    public function store(StoreProductionPlanRequest $request): JsonResponse
    {
        $plan = ProductionPlan::create($request->validated() + ['created_by_id' => $this->userId($request)]);

        return response()->json(['data' => $this->present($plan, true)], 201);
    }

    public function update(UpdateProductionPlanRequest $request, ProductionPlan $productionPlan): JsonResponse
    {
        DB::transaction(function () use ($request, $productionPlan): void {
            $productionPlan->fill(array_intersect_key($request->validated(), array_flip(['name', 'notes'])));
            $productionPlan->save();

            if ($request->has('rows')) {
                $productionPlan->rows()->delete();
                /** @var list<array<string, mixed>> $rows */
                $rows = (array) $request->validated('rows', []);
                foreach ($rows as $i => $row) {
                    $productionPlan->rows()->create([
                        'base_item_id' => $row['base_item_id'],
                        'quantity' => $row['quantity'],
                        'plan_unit' => $row['plan_unit'],
                        'new_vintage' => $row['new_vintage'] ?? null,
                        'sort_order' => $row['sort_order'] ?? $i,
                    ]);
                }
            }
        });

        return response()->json(['data' => $this->present($productionPlan->refresh(), true)]);
    }

    public function destroy(ProductionPlan $productionPlan): JsonResponse
    {
        if ($productionPlan->status !== ProductionPlanStatus::Draft) {
            throw ValidationException::withMessages(['plan' => 'Only a draft plan can be deleted.']);
        }
        $productionPlan->delete();

        return response()->json(status: 204);
    }

    public function calculate(ProductionPlan $productionPlan): JsonResponse
    {
        return response()->json(['data' => $this->calculator->calculate($productionPlan)]);
    }

    public function confirm(ConfirmPlanRequest $request, ProductionPlan $productionPlan): JsonResponse
    {
        $result = $this->confirmation->confirm($productionPlan, (bool) $request->validated('force', false));

        if ($result['conflicts'] !== []) {
            return response()->json(['data' => ['conflicts' => $result['conflicts']], 'code' => 'vintage_conflicts'], 409);
        }

        return response()->json(['data' => $this->present($result['plan'], true)]);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(ProductionPlan $plan, bool $detail = false): array
    {
        $payload = [
            'id' => $plan->getKey(),
            'name' => $plan->name,
            'status' => $plan->status->value,
            'confirmed_at' => $plan->confirmed_at?->toIso8601String(),
            'notes' => $plan->notes,
            'rows_count' => (int) ($plan->getAttribute('rows_count') ?? $plan->rows()->count()),
        ];

        if ($detail) {
            $plan->loadMissing('rows.baseItem');
            $payload['rows'] = $plan->rows->sortBy('sort_order')->values()->map(fn (ProductionPlanRow $r) => [
                'id' => $r->getKey(),
                'base_item_id' => $r->base_item_id,
                'base_item_name' => $r->baseItem?->name,
                'new_vintage' => $r->new_vintage,
                'created_item_id' => $r->created_item_id,
                'quantity' => (string) $r->quantity,
                'plan_unit' => $r->plan_unit->value,
                'sort_order' => $r->sort_order,
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
