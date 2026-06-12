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
    public function __construct(
        private readonly AiClient $ai,
        private readonly BddCompilerAgent $agent,
        private readonly PlanValidator $validator,
    ) {}

    public function compile(BddScenario $scenario): BddScenario
    {
        $scenario->update([
            'status' => BddScenarioStatus::Compiling,
            'compile_error' => null,
            'requested_operations' => null,
        ]);

        try {
            $response = $this->ai->prompt(
                $this->agent,
                $this->agent->userPrompt($scenario->gherkin),
                AiCapability::Text,
                'bdd_compiler',
            );

            $structured = $response instanceof StructuredAgentResponse ? $response->toArray() : [];
            $compiled = $this->agent->toPlan($structured);

            if ($compiled['errors'] !== []) {
                return $this->failed($scenario, implode(' ', $compiled['errors']));
            }

            // The agent reported steps it could not bind → ask for access.
            if ($compiled['unbound'] !== []) {
                return $this->needsAccess($scenario, array_values(array_unique(array_map(
                    fn (array $entry): string => (string) $entry['suggested_operation'],
                    $compiled['unbound'],
                ))), $compiled['unbound']);
            }

            $validation = $this->validator->validate($compiled['plan']);

            // Defense in depth: the agent bound an op that isn't actually granted.
            if ($validation['ungranted'] !== []) {
                return $this->needsAccess($scenario, $validation['ungranted'], array_map(
                    fn (string $key): array => ['step_text' => '', 'suggested_operation' => $key, 'why' => 'Bound by the compiler but not granted.'],
                    $validation['ungranted'],
                ));
            }

            if ($validation['errors'] !== []) {
                return $this->failed($scenario, implode(' ', $validation['errors']));
            }

            $scenario->update([
                'status' => BddScenarioStatus::Ready,
                'compiled_plan' => $compiled['plan'],
                'compile_model' => $response->meta->model ?? null,
            ]);
        } catch (Throwable $e) {
            return $this->failed($scenario, $e->getMessage());
        }

        return $scenario->refresh();
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
