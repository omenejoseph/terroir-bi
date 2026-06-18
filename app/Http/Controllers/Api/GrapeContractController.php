<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Vineyards\StoreGrapeContractRequest;
use App\Http\Requests\Vineyards\UpdateGrapeContractRequest;
use App\Models\GrapeContract;
use App\Models\Supplier;
use App\Queries\GrowerPerformanceQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class GrapeContractController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $contracts = GrapeContract::query()
            ->when($request->query('season'), fn ($q, $s) => $q->where('season', $s))
            ->orderByDesc('season')
            ->get();

        return response()->json(['data' => $contracts->map(fn (GrapeContract $c) => $this->present($c))->all()]);
    }

    public function store(StoreGrapeContractRequest $request): JsonResponse
    {
        $contract = GrapeContract::create($request->validated());
        // Mark the supplier as a cooperant grower.
        Supplier::query()->whereKey($contract->supplier_id)->update(['is_cooperant' => true]);

        return response()->json(['data' => $this->present($contract)], 201);
    }

    public function update(UpdateGrapeContractRequest $request, GrapeContract $grapeContract): JsonResponse
    {
        $grapeContract->update($request->validated());

        return response()->json(['data' => $this->present($grapeContract)]);
    }

    public function destroy(GrapeContract $grapeContract): JsonResponse
    {
        if ((float) $grapeContract->delivered_kg > 0) {
            throw ValidationException::withMessages(['contract' => 'A contract with deliveries cannot be deleted.']);
        }
        $grapeContract->delete();

        return response()->json(status: 204);
    }

    public function performance(Supplier $supplier, GrowerPerformanceQuery $query): JsonResponse
    {
        return response()->json(['data' => $query->forSupplier($supplier->getKey())]);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(GrapeContract $c): array
    {
        return [
            'id' => $c->getKey(),
            'supplier_id' => $c->supplier_id,
            'parcel_id' => $c->parcel_id,
            'season' => $c->season,
            'status' => $c->status->value,
            'grape_variety' => $c->grape_variety,
            'estimated_kg' => (string) $c->estimated_kg,
            'delivered_kg' => (string) $c->delivered_kg,
            'price_per_kg' => $c->price_per_kg->jsonSerialize(),
            'min_brix' => $c->min_brix,
            'max_ph' => $c->max_ph,
            'delivery_window' => $c->delivery_window,
            'payment_terms' => $c->payment_terms,
            'notes' => $c->notes,
        ];
    }
}
