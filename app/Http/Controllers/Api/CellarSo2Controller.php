<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\Cellar\Activities\CreateSo2RoundAction;
use App\Actions\Cellar\ApplySo2Action;
use App\Enums\WineType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Cellar\Activities\StoreSo2RoundRequest;
use App\Http\Requests\Cellar\CalculatorRequest;
use App\Http\Requests\Cellar\So2DoseRequest;
use App\Models\User;
use App\Queries\CellarSo2BoardQuery;
use App\Services\Cellar\EnologicalCalculator;
use App\Services\Cellar\So2Calculator;
use App\Services\Cellar\So2DosePlanner;
use App\Services\Cellar\WineCompliance;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Cellar › SO₂: the board, live dose preview, "Apply now", "Plan for cellar
 * team" — all doses computed server-side by So2DosePlanner — plus the
 * enological calculators.
 */
class CellarSo2Controller extends Controller
{
    public function board(CellarSo2BoardQuery $query): JsonResponse
    {
        return response()->json(['data' => $query->get()]);
    }

    public function preview(So2DoseRequest $request, So2DosePlanner $planner): JsonResponse
    {
        /** @var array{enological_product_id: string, mode?: string|null, items: list<array{wine_lot_id: string, vessel_id?: string|null, mg_l: float|int|string}>} $data */
        $data = $request->validated();

        return response()->json(['data' => $planner->plan($data)]);
    }

    public function apply(So2DoseRequest $request, ApplySo2Action $action): JsonResponse
    {
        /** @var array{enological_product_id: string, mode?: string|null, note?: string|null, items: list<array{wine_lot_id: string, vessel_id?: string|null, mg_l: float|int|string}>} $data */
        $data = $request->validated();

        return response()->json(['data' => $action->execute($data, $this->userId($request))], 201);
    }

    public function planRound(StoreSo2RoundRequest $request, CreateSo2RoundAction $action): JsonResponse
    {
        /** @var array{enological_product_id: string, mode?: string|null, note?: string|null, name?: string|null, planned_for: string, assigned_to_id?: string|null, items: list<array{wine_lot_id: string, vessel_id?: string|null, mg_l: float|int|string}>} $data */
        $data = $request->validated();

        return response()->json(['data' => $action->execute($data, $this->userId($request))], 201);
    }

    public function calculate(CalculatorRequest $request, So2Calculator $so2, EnologicalCalculator $calc, WineCompliance $compliance): JsonResponse
    {
        $f = fn (string $key): float => (float) $request->validated($key);

        $result = match ((string) $request->validated('kind')) {
            'so2' => [
                'grams_k2s2o5' => $so2->gramsK2S2O5($f('target_free_so2'), $f('current_free_so2'), $f('volume_liters')),
                'molecular' => $so2->molecular($f('current_free_so2'), $f('ph')),
                'molecular_after' => $so2->molecular($f('target_free_so2'), $f('ph')),
            ],
            'acid' => $calc->acidAddition($f('volume_liters'), $f('current_ta'), $f('target_ta')),
            'addition' => $calc->generalAddition(
                $f('volume_liters'),
                $f('dose_mg_l'),
                $request->validated('concentration_percent') !== null ? $f('concentration_percent') : null,
            ),
            'chaptalization' => $calc->chaptalization($f('volume_liters'), $f('current_brix'), $f('target_brix')),
            'press_yield' => $calc->pressYield(
                $f('grape_kg'),
                $f('yield_ratio'),
                array_values(array_map(
                    fn (array $fr): array => ['type' => (string) $fr['type'], 'volume_liters' => (float) $fr['volume_liters']],
                    (array) $request->validated('fractions'),
                )),
            ),
            'so2_compliance' => $compliance->checkSo2(
                $f('total_so2'),
                WineType::from((string) $request->validated('wine_type')),
                $request->validated('residual_sugar') !== null ? $f('residual_sugar') : null,
            ),
            default => [],
        };

        return response()->json(['data' => $result]);
    }

    private function userId(Request $request): string
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return $user->getKey();
    }
}
