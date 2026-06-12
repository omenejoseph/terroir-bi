<?php

declare(strict_types=1);

namespace App\Services\Bdd;

use App\Authorization\MembershipContext;
use App\Enums\AiCapability;
use App\Enums\BddRunStatus;
use App\Models\BddScenario;
use App\Models\BddScenarioRun;
use App\Models\Membership;
use App\Models\Tenant;
use App\Services\Ai\Agents\LiveBddAgent;
use App\Services\Ai\AiClient;
use App\Services\Bdd\Tools\FinishTool;
use App\Services\Bdd\Tools\GivenTool;
use App\Services\Bdd\Tools\ProbeTool;
use App\Services\Bdd\Tools\ThenTool;
use App\Services\Bdd\Tools\WhenTool;
use App\Tenancy\Contracts\TenantContext;
use Illuminate\Support\Facades\DB;
use Laravel\Ai\Responses\AgentResponse;
use RuntimeException;
use Throwable;

/**
 * Executes a BDD scenario LIVE: an AI agent drives the Gherkin through tools
 * (seed/action/probe/judgement) inside an ALWAYS-ROLLED-BACK transaction
 * against a throwaway sandbox tenant. Every run calls the model — there is no
 * saved plan — and the model gets each tool result back immediately, so it
 * self-corrects argument mistakes within the run.
 *
 * Trust model: probes return real database state, infrastructure errors and
 * missing grants are detected IN CODE, and the full tool transcript is
 * persisted — but each Then's pass/fail is the model's judgement. A green run
 * is therefore strong evidence, not proof; treat it as an admin-triggered
 * check, not a strict CI gate.
 *
 * Guard rails (see also OperationRegistry/SandboxContext/Tools\BddTool):
 *  - one beginTransaction … finally rollBack wraps the entire run, pass or
 *    fail, so the sandbox tenant and everything the tools created vanish;
 *  - every action is grant-checked again at call time (fail-closed);
 *  - model arguments resolve ONLY from this run's captures and are verified to
 *    belong to the sandbox before any invocation;
 *  - the tenant context is re-asserted on every tool call and after the loop;
 *  - the loop is capped (LiveBddAgent::maxSteps) and wall-clocked (timeout);
 *  - a post-run leak check confirms the sandbox row is gone.
 */
class LiveScenarioRunner
{
    /** Wall-clock cap for the whole tool loop, in seconds. */
    public const TIMEOUT_SECONDS = 180;

    public function __construct(
        private readonly OperationRegistry $registry,
        private readonly ActionInvoker $invoker,
        private readonly SandboxFactory $sandboxFactory,
        private readonly AiClient $ai,
        private readonly TenantContext $tenantContext,
        private readonly MembershipContext $membershipContext,
    ) {}

    public function run(BddScenario $scenario, ?string $triggeredById = null): BddScenarioRun
    {
        $startedAt = hrtime(true);

        if (! $scenario->isRunnable()) {
            return $this->persist($scenario, BddRunStatus::Error, [], 'The scenario has no Gherkin to execute.', null, $startedAt, $triggeredById);
        }

        if (! $this->ai->enabled()) {
            return $this->persist($scenario, BddRunStatus::Error, [], 'AI is disabled — live runs need an enabled Text capability.', null, $startedAt, $triggeredById);
        }

        $status = BddRunStatus::Error;
        $error = null;
        $context = null;
        $response = null;
        $prompted = false;

        $previousTenant = $this->tenantContext->current();
        $previousMembership = $this->membershipContext->current();
        $sandboxTenantId = null;

        DB::beginTransaction();

        try {
            $sandbox = $this->sandboxFactory->create();
            $sandboxTenantId = $sandbox->tenant->getKey();

            $this->tenantContext->makeCurrent($sandbox->tenant);
            $membership = Membership::query()
                ->where('tenant_id', $sandbox->tenant->getKey())
                ->where('user_id', $sandbox->admin->getKey())
                ->firstOrFail();
            $this->membershipContext->set($membership);

            $context = new LiveExecutionContext($sandbox);
            $agent = $this->agent($context, $sandbox);

            $prompted = true;
            /** @var AgentResponse $response */
            $response = $this->ai->prompt(
                $agent,
                $agent->userPrompt($scenario->gherkin),
                AiCapability::Text,
                'bdd_live_run',
                timeout: self::TIMEOUT_SECONDS,
            );

            // Guard rail: the loop must not have swapped the tenant context.
            if ($this->tenantContext->currentId() !== (string) $sandbox->tenant->getKey()) {
                throw new RuntimeException('Guard rail: the run mutated the tenant context — run aborted.');
            }

            $context->recordTranscript(['assistant' => (string) $response]);

            $status = $context->verdict();
            $error = $context->error();
        } catch (Throwable $e) {
            $status = BddRunStatus::Error;
            $error = $e->getMessage();
        } finally {
            DB::rollBack();

            // Restore whatever contexts were bound before the run.
            if ($previousTenant !== null) {
                $this->tenantContext->makeCurrent($previousTenant);
            } else {
                $this->tenantContext->forget();
            }
            if ($previousMembership !== null) {
                $this->membershipContext->set($previousMembership);
            } else {
                $this->membershipContext->forget();
            }
        }

        // The AI call's usage log row was rolled back with the sandbox — relog
        // it now so per-tenant spend reporting stays accurate.
        if ($prompted) {
            $this->ai->recordUsage(AiCapability::Text, 'bdd_live_run', $response, $response !== null);
        }

        // Guard rail: leak check — the sandbox must have vanished with the rollback.
        if ($sandboxTenantId !== null && Tenant::query()->whereKey($sandboxTenantId)->exists()) {
            $status = BddRunStatus::Error;
            $error = trim(($error ?? '').' Guard rail: sandbox tenant survived the rollback — investigate immediately.');
        }

        return $this->persist(
            $scenario,
            $status,
            $context?->stepResults() ?? [],
            $error,
            $context?->transcript(),
            $startedAt,
            $triggeredById,
        );
    }

    /** The per-run agent: five tools sharing this run's context, inside this run's sandbox. */
    private function agent(LiveExecutionContext $context, SandboxContext $sandbox): LiveBddAgent
    {
        return new LiveBddAgent($this->registry, [
            new GivenTool($context, $this->tenantContext, new SeedOperations($sandbox)),
            new WhenTool($context, $this->tenantContext, $this->registry, $this->invoker),
            new ProbeTool($context, $this->tenantContext, new ProbeOperations($sandbox)),
            new ThenTool($context, $this->tenantContext),
            new FinishTool($context, $this->tenantContext),
        ]);
    }

    /**
     * Persist the run AFTER the rollback so the record survives.
     *
     * @param  list<array<string, mixed>>  $stepResults
     * @param  list<array<string, mixed>>|null  $transcript
     */
    private function persist(
        BddScenario $scenario,
        BddRunStatus $status,
        array $stepResults,
        ?string $error,
        ?array $transcript,
        int $startedAt,
        ?string $triggeredById,
    ): BddScenarioRun {
        $run = BddScenarioRun::create([
            'bdd_scenario_id' => $scenario->getKey(),
            'status' => $status,
            'step_results' => $stepResults,
            'error' => $error,
            'transcript' => $transcript,
            'duration_ms' => (int) ((hrtime(true) - $startedAt) / 1_000_000),
            'triggered_by_id' => $triggeredById,
        ]);

        $scenario->update([
            'last_run_status' => $status,
            'last_run_at' => now(),
        ]);

        return $run;
    }
}
