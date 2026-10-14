<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\Cellar\Activities\CompleteCellarActivityAction;
use App\Actions\Cellar\Activities\CreateCellarActivitiesAction;
use App\Actions\Cellar\Activities\CreateSo2RoundAction;
use App\Actions\Cellar\Activities\DeleteCellarActivityAction;
use App\Actions\Cellar\Activities\DeleteCellarRoundAction;
use App\Actions\Cellar\Activities\PlanProtocolAdditionsAction;
use App\Actions\Cellar\Activities\RescheduleCellarActivityAction;
use App\Actions\Cellar\Activities\RescheduleCellarRoundAction;
use App\Actions\Cellar\Activities\SkipCellarActivityAction;
use App\DataTransferObjects\CellarActivityData;
use App\Enums\CellarActivitySkipReason;
use App\Http\Controllers\Controller;
use App\Http\Requests\Cellar\Activities\CompleteCellarActivityRequest;
use App\Http\Requests\Cellar\Activities\PlanProtocolAdditionsRequest;
use App\Http\Requests\Cellar\Activities\RescheduleCellarActivityRequest;
use App\Http\Requests\Cellar\Activities\SkipCellarActivityRequest;
use App\Http\Requests\Cellar\Activities\StoreCellarActivitiesRequest;
use App\Http\Requests\Cellar\Activities\StoreSo2RoundRequest;
use App\Models\CellarActivity;
use App\Models\CellarActivityRound;
use App\Models\User;
use App\Models\WineLot;
use App\Queries\CellarPlanQuery;
use App\Queries\CellarTodayQuery;
use App\Services\Cellar\CellarClock;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Planned cellar work: the Today and Plan views, planning (incl. from a lot's
 * protocol and from the SO₂ screen), and executing / skipping / moving items.
 */
class CellarActivityController extends Controller
{
    public function today(Request $request, CellarTodayQuery $query): JsonResponse
    {
        return response()->json(['data' => $query->get($this->userId($request), $request->boolean('mine'))]);
    }

    public function plan(CellarPlanQuery $query): JsonResponse
    {
        return response()->json(['data' => $query->get()]);
    }

    public function store(StoreCellarActivitiesRequest $request, CreateCellarActivitiesAction $action): JsonResponse
    {
        /** @var array{round_name?: string|null, assigned_to_id?: string|null, activities: list<array<string, mixed>>} $data */
        $data = $request->validated();

        return response()->json(['data' => $action->execute($data, $this->userId($request))], 201);
    }

    public function complete(CompleteCellarActivityRequest $request, CellarActivity $activity, CompleteCellarActivityAction $action, CellarClock $clock): JsonResponse
    {
        /** @var array{quantity?: float|int|string|null, liters?: float|int|string|null, values?: array<string, mixed>|null, note?: string|null} $overrides */
        $overrides = $request->validated();
        $activity = $action->execute($activity, $overrides, $this->userId($request));

        return response()->json(['data' => $this->present($activity, $clock)]);
    }

    public function skip(SkipCellarActivityRequest $request, CellarActivity $activity, SkipCellarActivityAction $action, CellarClock $clock): JsonResponse
    {
        $activity = $action->execute(
            $activity,
            CellarActivitySkipReason::from((string) $request->validated('reason')),
            $request->validated('note'),
            $this->userId($request),
        );

        return response()->json(['data' => $this->present($activity, $clock)]);
    }

    public function reschedule(RescheduleCellarActivityRequest $request, CellarActivity $activity, RescheduleCellarActivityAction $action, CellarClock $clock): JsonResponse
    {
        $activity = $action->execute($activity, (string) $request->validated('planned_for'), $request->validated('reason'));

        return response()->json(['data' => $this->present($activity, $clock)]);
    }

    public function destroy(CellarActivity $activity, DeleteCellarActivityAction $action): JsonResponse
    {
        $action->execute($activity);

        return response()->json(status: 204);
    }

    public function rescheduleRound(RescheduleCellarActivityRequest $request, CellarActivityRound $round, RescheduleCellarRoundAction $action): JsonResponse
    {
        $moved = $action->execute($round, (string) $request->validated('planned_for'), $request->validated('reason'));

        return response()->json(['data' => ['moved' => $moved]]);
    }

    public function destroyRound(CellarActivityRound $round, DeleteCellarRoundAction $action): JsonResponse
    {
        return response()->json(['data' => $action->execute($round)]);
    }

    public function planProtocolAdditions(PlanProtocolAdditionsRequest $request, WineLot $wineLot, PlanProtocolAdditionsAction $action): JsonResponse
    {
        return response()->json([
            'data' => $action->execute($wineLot, (string) $request->validated('planned_for'), $this->userId($request)),
        ], 201);
    }

    public function storeSo2Round(StoreSo2RoundRequest $request, CreateSo2RoundAction $action): JsonResponse
    {
        /** @var array{name?: string|null, planned_for: string, assigned_to_id?: string|null, enological_product_id?: string|null, product_name: string, unit: string, cost_per_unit?: int|null, items: list<array{wine_lot_id: string, vessel_id?: string|null, quantity: float|int|string, target_note?: string|null}>} $data */
        $data = $request->validated();

        return response()->json(['data' => $action->execute($data, $this->userId($request))], 201);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(CellarActivity $activity, CellarClock $clock): array
    {
        $activity->load(['wineLot', 'vessel', 'round', 'assignedTo', 'executedBy']);

        return CellarActivityData::fromModel($activity, $clock->today())->toArray();
    }

    private function userId(Request $request): string
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return $user->getKey();
    }
}
