<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\Cellar;

use App\Actions\Cellar\Activities\CompleteCellarActivityAction;
use App\Actions\Cellar\Activities\CreateCellarActivitiesAction;
use App\Actions\Cellar\Activities\DeleteCellarActivityAction;
use App\Actions\Cellar\Activities\DeleteCellarRoundAction;
use App\Actions\Cellar\Activities\PlanProtocolAdditionsAction;
use App\Actions\Cellar\Activities\RescheduleCellarActivityAction;
use App\Actions\Cellar\Activities\RescheduleCellarRoundAction;
use App\Actions\Cellar\Activities\SkipCellarActivityAction;
use App\Authorization\MembershipContext;
use App\Enums\CellarActivitySkipReason;
use App\Http\Controllers\Controller;
use App\Http\Requests\Cellar\Activities\CompleteCellarActivityRequest;
use App\Http\Requests\Cellar\Activities\PlanProtocolAdditionsRequest;
use App\Http\Requests\Cellar\Activities\RescheduleCellarActivityRequest;
use App\Http\Requests\Cellar\Activities\SkipCellarActivityRequest;
use App\Http\Requests\Cellar\Activities\StoreCellarActivitiesRequest;
use App\Models\CellarActivity;
use App\Models\CellarActivityRound;
use App\Models\User;
use App\Models\WineLot;
use App\Queries\CellarPickerOptions;
use App\Queries\CellarPlanQuery;
use App\Queries\CellarTodayQuery;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Inertia counterpart of Api\CellarActivityController: Cellar › Today (doing
 * the planned work) and Cellar › Plan (planning it). Same queries and Actions
 * as the API.
 */
class CellarActivityController extends Controller
{
    public function today(Request $request, CellarTodayQuery $query): Response
    {
        $mine = $request->boolean('mine');

        return Inertia::render('Cellar/Today', [
            'today' => $query->get($this->userId($request), $mine),
            'mine' => $mine,
        ]);
    }

    /**
     * The planner's pickers are sent with every visit (not as optional props):
     * planning redirects back here, and a full visit would otherwise drop
     * them mid-session. Members who cannot plan don't pay for them.
     */
    public function plan(CellarPlanQuery $query, CellarPickerOptions $options, MembershipContext $membership): Response
    {
        $manage = $membership->can('cellar.manage');

        return Inertia::render('Cellar/Plan', [
            'plan' => $query->get(),
            'lots' => fn (): array => $manage ? $options->lots() : [],
            'vessels' => fn (): array => $options->vessels(),
            'products' => fn (): array => $manage ? $options->products() : [],
            'members' => fn (): array => $manage ? $options->members() : [],
            'parcels' => fn (): array => $manage ? $options->parcels() : [],
        ]);
    }

    public function store(StoreCellarActivitiesRequest $request, CreateCellarActivitiesAction $action): RedirectResponse
    {
        /** @var array{round_name?: string|null, assigned_to_id?: string|null, activities: list<array<string, mixed>>} $data */
        $data = $request->validated();
        $result = $action->execute($data, $this->userId($request));

        return back()->with('success', __('Planned :n tasks.', ['n' => $result['count']]));
    }

    public function complete(CompleteCellarActivityRequest $request, CellarActivity $activity, CompleteCellarActivityAction $action): RedirectResponse
    {
        /** @var array{quantity?: float|int|string|null, liters?: float|int|string|null, values?: array<string, mixed>|null, note?: string|null} $overrides */
        $overrides = $request->validated();
        $action->execute($activity, $overrides, $this->userId($request));

        return back();
    }

    public function skip(SkipCellarActivityRequest $request, CellarActivity $activity, SkipCellarActivityAction $action): RedirectResponse
    {
        $action->execute(
            $activity,
            CellarActivitySkipReason::from((string) $request->validated('reason')),
            $request->validated('note'),
            $this->userId($request),
        );

        return back();
    }

    public function reschedule(RescheduleCellarActivityRequest $request, CellarActivity $activity, RescheduleCellarActivityAction $action): RedirectResponse
    {
        $action->execute($activity, (string) $request->validated('planned_for'), $request->validated('reason'));

        return back()->with('success', __('Task moved.'));
    }

    public function destroy(CellarActivity $activity, DeleteCellarActivityAction $action): RedirectResponse
    {
        $action->execute($activity);

        return back()->with('success', __('Task removed.'));
    }

    public function rescheduleRound(RescheduleCellarActivityRequest $request, CellarActivityRound $round, RescheduleCellarRoundAction $action): RedirectResponse
    {
        $action->execute($round, (string) $request->validated('planned_for'), $request->validated('reason'));

        return back()->with('success', __('Round moved.'));
    }

    public function destroyRound(CellarActivityRound $round, DeleteCellarRoundAction $action): RedirectResponse
    {
        $action->execute($round);

        return back()->with('success', __('Round deleted.'));
    }

    public function planProtocolAdditions(PlanProtocolAdditionsRequest $request, WineLot $wineLot, PlanProtocolAdditionsAction $action): RedirectResponse
    {
        $result = $action->execute($wineLot, (string) $request->validated('planned_for'), $this->userId($request));

        return back()->with('success', __('Planned :n protocol additions for the cellar team.', ['n' => $result['count']]));
    }

    private function userId(Request $request): string
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return $user->getKey();
    }
}
