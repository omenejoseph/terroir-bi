<?php

declare(strict_types=1);

namespace App\Services\Bdd;

use App\Enums\AiCapability;
use App\Enums\BddScenarioStatus;
use App\Models\BddScenario;
use App\Services\Ai\Agents\BddCompilerAgent;
use App\Services\Ai\AiClient;
use Laravel\Ai\Responses\StructuredAgentResponse;
use Throwable;

/**
 * Compiles a scenario's Gherkin into a saved execution plan via the AI agent.
 * Outcomes (fail-closed):
 *  - READY          plan validated and saved; runnable with zero AI cost,
 *  - NEEDS_ACCESS   the agent (or validator) hit ungranted operations — they
 *                   are saved on the scenario for one-click granting,
 *  - COMPILE_FAILED the model/plan was unusable; the error is stored.
 *
 * The compiler runs with NO tenant context: the agent only ever sees static
 * operation metadata, never data.
 */
class ScenarioCompiler
{
    /** Compile attempts: first pass + one self-correction with error feedback. */
    private const MAX_ATTEMPTS = 2;

    public function __construct(
        private readonly AiClient $ai,
        private readonly BddCompilerAgent $agent,
        private readonly PlanValidator $validator,
        private readonly OperationRegistry $registry,
    ) {}

    public function compile(BddScenario $scenario): BddScenario
    {
        $scenario->update([
            'status' => BddScenarioStatus::Compiling,
            'compile_error' => null,
            'requested_operations' => null,
        ]);

        try {
            $errors = [];
            $compiled = ['plan' => ['version' => 1, 'steps' => []], 'unbound' => [], 'errors' => []];
            $model = null;

            // Up to two attempts: the first compiles the Gherkin; a second
            // self-correction turn feeds the validator's complaints back so the
            // model can fix structural slips (a missing prerequisite seed, an
            // undefined $reference). Access decisions are terminal — never retried.
            for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {
                $prompt = $attempt === 1
                    ? $this->agent->userPrompt($scenario->gherkin)
                    : $this->agent->retryPrompt($scenario->gherkin, (string) json_encode($compiled['plan']), $errors);

                $response = $this->ai->prompt($this->agent, $prompt, AiCapability::Text, 'bdd_compiler');
                $model = $response->meta->model ?? $model;

                $structured = $response instanceof StructuredAgentResponse ? $response->toArray() : [];
                $compiled = $this->agent->toPlan($structured);

                // Genuine access gap → park for one-click granting (terminal).
                // An entry for an operation that is ALREADY granted is model
                // noise (the action sits in the available catalog, yet the model
                // also listed it as unbound) — ignore it, or the scenario parks
                // asking for access it already has and loops on every recompile.
                $realUnbound = array_values(array_filter(
                    $compiled['unbound'],
                    function (array $entry): bool {
                        $key = (string) ($entry['suggested_operation'] ?? '');

                        return $this->registry->isRequestableAction($key) && ! $this->registry->isGranted($key);
                    },
                ));
                if ($realUnbound !== []) {
                    return $this->needsAccess($scenario, array_values(array_unique(array_map(
                        fn (array $entry): string => (string) $entry['suggested_operation'],
                        $realUnbound,
                    ))), $realUnbound);
                }

                $validation = $this->validator->validate($compiled['plan']);

                // Defense in depth: the agent bound an op that isn't granted (terminal).
                if ($validation['ungranted'] !== []) {
                    return $this->needsAccess($scenario, $validation['ungranted'], array_map(
                        fn (string $key): array => ['step_text' => '', 'suggested_operation' => $key, 'why' => 'Bound by the compiler but not granted.'],
                        $validation['ungranted'],
                    ));
                }

                $errors = [...$compiled['errors'], ...$validation['errors']];

                if ($errors === []) {
                    $scenario->update([
                        'status' => BddScenarioStatus::Ready,
                        'compiled_plan' => $compiled['plan'],
                        'compile_model' => $model,
                    ]);

                    return $scenario->refresh();
                }
            }

            // Both attempts produced an invalid plan.
            return $this->failed($scenario, implode(' ', $errors));
        } catch (Throwable $e) {
            return $this->failed($scenario, $e->getMessage());
        }
    }

    /**
     * @param  list<string>  $operations
     * @param  list<array<string, string|null>>  $detail
     */
    private function needsAccess(BddScenario $scenario, array $operations, array $detail): BddScenario
    {
        $scenario->update([
            'status' => BddScenarioStatus::NeedsAccess,
            'compiled_plan' => null,
            'requested_operations' => $detail,
            'compile_error' => 'Needs access to: '.implode(', ', $operations),
        ]);

        return $scenario->refresh();
    }

    private function failed(BddScenario $scenario, string $error): BddScenario
    {
        $scenario->update([
            'status' => BddScenarioStatus::CompileFailed,
            'compiled_plan' => null,
            'compile_error' => $error,
        ]);

        return $scenario->refresh();
    }
}
