<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\Cellar;

use App\Actions\Cellar\Activities\CreateSo2RoundAction;
use App\Actions\Cellar\ApplySo2Action;
use App\Actions\Cellar\BulkAddAnalysesAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Cellar\Activities\StoreSo2RoundRequest;
use App\Http\Requests\Cellar\BulkAnalysesRequest;
use App\Http\Requests\Cellar\So2DoseRequest;
use App\Models\User;
use App\Queries\CellarPickerOptions;
use App\Queries\CellarSo2BoardQuery;
use App\Services\Cellar\So2DosePlanner;
use App\Support\Cellar\CellarPreselect;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Inertia counterpart of Api\CellarSo2Controller: Cellar › SO₂. Doses are
 * always computed by So2DosePlanner on the server; the live preview is the
 * one JSON route, called while the winemaker types.
 */
class CellarSo2Controller extends Controller
{
    public function index(Request $request, CellarSo2BoardQuery $query, CellarPickerOptions $options): Response
    {
        return Inertia::render('Cellar/So2', [
            'board' => $query->get(),
            'preselect' => CellarPreselect::from($request),
            'members' => Inertia::optional(fn (): array => $options->members()),
        ]);
    }

    public function preview(So2DoseRequest $request, So2DosePlanner $planner): JsonResponse
    {
        /** @var array{enological_product_id: string, mode?: string|null, items: list<array{wine_lot_id: string, vessel_id?: string|null, mg_l: float|int|string}>} $data */
        $data = $request->validated();

        return response()->json(['data' => $planner->plan($data)]);
    }

    public function apply(So2DoseRequest $request, ApplySo2Action $action): RedirectResponse
    {
        /** @var array{enological_product_id: string, mode?: string|null, note?: string|null, items: list<array{wine_lot_id: string, vessel_id?: string|null, mg_l: float|int|string}>} $data */
        $data = $request->validated();
        $plan = $action->execute($data, $this->userId($request));
        $lines = is_array($plan['lines'] ?? null) ? count($plan['lines']) : 0;

        return back()->with('success', __('SO₂ applied to :n vessels.', ['n' => $lines]));
    }

    public function planRound(StoreSo2RoundRequest $request, CreateSo2RoundAction $action): RedirectResponse
    {
        /** @var array{enological_product_id: string, mode?: string|null, note?: string|null, name?: string|null, planned_for: string, assigned_to_id?: string|null, items: list<array{wine_lot_id: string, vessel_id?: string|null, mg_l: float|int|string}>} $data */
        $data = $request->validated();
        $result = $action->execute($data, $this->userId($request));

        return back()->with('success', __('Round planned: :n vessels — the cellar team sees it on Today.', ['n' => $result['count']]));
    }

    public function readings(BulkAnalysesRequest $request, BulkAddAnalysesAction $action): RedirectResponse
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = (array) $request->validated('analyses', []);
        $created = $action->execute($rows, $this->userId($request));

        return back()->with('success', __('Saved :n readings.', ['n' => count($created)]));
    }

    private function userId(Request $request): string
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return $user->getKey();
    }
}
