<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\Admin;

use App\Actions\Bdd\GrantBddOperationAction;
use App\Actions\Bdd\SaveBddScenarioAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\BddScenarios\StoreBddScenarioRequest;
use App\Http\Requests\Admin\BddScenarios\UpdateBddScenarioRequest;
use App\Models\BddScenario;
use App\Queries\Bdd\BddScenarioRunsQuery;
use App\Queries\Bdd\ListBddScenariosQuery;
use App\Services\Bdd\BddRunLog;
use App\Services\Bdd\CurrentOperator;
use App\Services\Bdd\LiveScenarioRunner;
use App\Support\PerPage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;
use Throwable;

/**
 * Port of App\Filament\Resources\BddScenarios\**, an AI test-runner UI, not
 * plain CRUD. Filament's `wire:poll` has no Inertia equivalent: the list's
 * 10s status refresh uses a plain Inertia partial reload (Admin/BddScenarios/
 * Index.vue), the detail page's 2s live-run-log refresh uses the `status()`
 * JSON endpoint below (same precedent as NotificationsPanel.vue's polling).
 */
class BddScenarioController extends Controller
{
    public function index(Request $request, ListBddScenariosQuery $query): Response
    {
        $search = trim((string) $request->query('search', ''));

        $scenarios = $query->builder()
            ->when($search !== '', fn ($q) => $q->where('title', 'like', "%{$search}%"))
            ->orderBy('title')
            ->paginate(PerPage::fromRequest($request), ['*'], 'page');

        return Inertia::render('Admin/BddScenarios/Index', [
            'scenarios' => [
                'data' => array_map(fn (BddScenario $s): array => $this->row($s), $scenarios->items()),
                'meta' => [
                    'current_page' => $scenarios->currentPage(),
                    'last_page' => $scenarios->lastPage(),
                    'per_page' => $scenarios->perPage(),
                    'total' => $scenarios->total(),
                ],
            ],
            'filters' => ['search' => $search !== '' ? $search : null],
        ]);
    }

    public function show(BddScenario $bddScenario, BddScenarioRunsQuery $runs): Response
    {
        return Inertia::render('Admin/BddScenarios/Show', [
            'scenario' => $this->present($bddScenario, $runs),
        ]);
    }

    /** Polled every 2s by the Show page while a run is in flight. */
    public function status(BddScenario $bddScenario, BddScenarioRunsQuery $runs): JsonResponse
    {
        return response()->json($this->present($bddScenario, $runs));
    }

    public function store(StoreBddScenarioRequest $request, SaveBddScenarioAction $action): RedirectResponse
    {
        $scenario = $action->execute($request->validated(), CurrentOperator::id());

        return redirect('/admin-new/bdd-scenarios/'.$scenario->getKey())->with('success', __('Scenario created.'));
    }

    public function update(UpdateBddScenarioRequest $request, BddScenario $bddScenario, SaveBddScenarioAction $action): RedirectResponse
    {
        $action->execute($request->validated(), CurrentOperator::id(), $bddScenario);

        return redirect('/admin-new/bdd-scenarios/'.$bddScenario->getKey())->with('success', __('Scenario updated.'));
    }

    public function destroy(BddScenario $bddScenario): RedirectResponse
    {
        $bddScenario->delete();

        return redirect('/admin-new/bdd-scenarios')->with('success', __('Scenario deleted.'));
    }

    /** Queue a live AI run — port of ScenarioActions::run(). */
    public function run(BddScenario $bddScenario, LiveScenarioRunner $runner): RedirectResponse
    {
        if (! $bddScenario->isRunnable() || ($bddScenario->last_run_status?->isInFlight() ?? false)) {
            abort(HttpResponse::HTTP_FORBIDDEN);
        }

        try {
            $runner->queue($bddScenario, CurrentOperator::id());
        } catch (Throwable $e) {
            return back()->with('error', __('Could not queue the run: :message', ['message' => $e->getMessage()]));
        }

        return back()->with('success', __('Run queued — open the scenario to watch the live log.'));
    }

    /** Queue a live AI run for every active/runnable scenario — port of ListBddScenarios' "Run all". */
    public function runAll(ListBddScenariosQuery $query, LiveScenarioRunner $runner): RedirectResponse
    {
        $scenarios = $query->runnable();

        if ($scenarios->isEmpty()) {
            return back()->with('error', __('No runnable scenarios.'));
        }

        foreach ($scenarios as $scenario) {
            $runner->queue($scenario, CurrentOperator::id());
        }

        return back()->with('success', __(':count runs queued — verdicts appear as workers finish.', ['count' => $scenarios->count()]));
    }

    /** One grant covering every operation the latest run was denied — port of ScenarioActions::grantRequested(). */
    public function grantAccess(BddScenario $bddScenario, BddScenarioRunsQuery $runs, GrantBddOperationAction $action): RedirectResponse
    {
        $denied = $runs->latestDeniedOperations($bddScenario);

        if ($denied === []) {
            return back();
        }

        $granted = [];
        foreach ($denied as $operation) {
            try {
                $action->execute($operation, CurrentOperator::id());
                $granted[] = $operation;
            } catch (Throwable) {
                // Skip; the remaining denied operations still get their chance.
            }
        }

        if ($granted === []) {
            return back()->with('error', __('Could not grant the requested access.'));
        }

        return back()->with('success', __('Access granted: :operations — run the scenario again.', ['operations' => implode(', ', $granted)]));
    }

    /**
     * @return array<string, mixed>
     */
    private function row(BddScenario $scenario): array
    {
        return [
            'id' => $scenario->getKey(),
            'title' => $scenario->title,
            'status' => $scenario->status->value,
            'last_run_status' => $scenario->last_run_status?->value,
            'last_run_at' => $scenario->last_run_at?->toIso8601String(),
            'is_active' => $scenario->is_active,
            'is_runnable' => $scenario->isRunnable(),
            'in_flight' => $scenario->last_run_status?->isInFlight() ?? false,
        ];
    }

    /**
     * The full scenario payload — port of BddScenarioInfolist, shared by
     * show() (Inertia) and status() (JSON polling) so the two can never
     * disagree about what's currently true.
     *
     * @return array<string, mixed>
     */
    private function present(BddScenario $scenario, BddScenarioRunsQuery $runs): array
    {
        $inFlight = $scenario->last_run_status?->isInFlight() ?? false;
        $run = $runs->latest($scenario);

        $liveLog = [];
        if ($inFlight && $run !== null) {
            $lines = app(BddRunLog::class)->lines((string) $run->getKey());
            $liveLog = $lines === [] ? [__('Waiting for a queue worker to pick the run up…')] : $lines;
        }

        $lastRunSteps = [];
        if (! $inFlight && $run !== null) {
            foreach (($run->step_results ?? []) as $step) {
                $lastRunSteps[] = sprintf(
                    '%s step %s [%s] %s — %s',
                    strtoupper((string) ($step['status'] ?? '')),
                    $step['index'] ?? '?',
                    $step['op'] ?? '',
                    $step['text'] ?? '',
                    $step['detail'] ?? '',
                );
            }
            if ($run->error !== null) {
                $lastRunSteps[] = 'ERROR: '.$run->error;
            }
        }

        $transcript = ! $inFlight && $run !== null && ($run->transcript ?? []) !== []
            ? json_encode($run->transcript, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            : null;

        return [
            'id' => $scenario->getKey(),
            'title' => $scenario->title,
            'gherkin' => $scenario->gherkin,
            'is_active' => $scenario->is_active,
            'status' => $scenario->status->value,
            'last_run_status' => $scenario->last_run_status?->value,
            'last_run_at' => $scenario->last_run_at?->toIso8601String(),
            'is_runnable' => $scenario->isRunnable(),
            'in_flight' => $inFlight,
            'live_log' => $liveLog,
            'denied_operations' => $inFlight ? [] : $runs->latestDeniedOperations($scenario),
            'last_run_steps' => $lastRunSteps,
            'run_log' => ! $inFlight && $run !== null ? ($run->logs ?? []) : [],
            'transcript' => $transcript,
        ];
    }
}
