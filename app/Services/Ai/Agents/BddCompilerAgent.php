<?php

declare(strict_types=1);

namespace App\Services\Ai\Agents;

use App\Services\Bdd\OperationRegistry;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Promptable;
use Stringable;

/**
 * Compiles a Gherkin BDD scenario into a deterministic execution plan bound to
 * the application's exposed (granted) operations. The agent NEVER sees tenant
 * data — its tools return only static operation metadata — and it must never
 * invent operations: a step it cannot bind goes into `unbound` so the admin
 * can grant access (fail-closed).
 *
 * Nested free-form values (args/assert/expect_error) travel as JSON-encoded
 * strings to keep the structured-output schema strict; ScenarioCompiler
 * decodes and validates them.
 */
class BddCompilerAgent implements Agent, Conversational, HasStructuredOutput, HasTools
{
    use Promptable;

    public function __construct(private readonly OperationRegistry $registry) {}

    public function instructions(): Stringable|string
    {
        return <<<'TXT'
        You compile Gherkin BDD scenarios into executable JSON plans for a wine
        business management application (orders, inventory, customers,
        consignment). You are given the catalog of available operations; use the
        tools to list and inspect them.

        Binding rules — follow them exactly:
        - Each Given step binds to a seed.* operation, each When step to an
          action:* operation, each Then step to a probe.* operation with an
          assertion. And/But continue the previous keyword.
        - ONLY use operations from the catalog. NEVER invent an operation. If a
          step needs something that is not available, add an entry to `unbound`
          (with the step text and the operation you believe is needed, e.g. the
          likely action class) and still emit the steps you COULD bind.
        - Entities are referenced by $capture variables: give a step a short
          snake_case `capture` name and reference it later as "$name" (the
          entity itself) or "$name.field" (an attribute, e.g. "$r3.id").
          NEVER write literal database ids.
        - All money is integer minor units (€12.00 → 1200). Stock quantities in
          seeds are numeric strings in the item's storage unit.
        - Action parameters named like createdById are auto-filled with the
          sandbox operator — omit them.
        - For steps that expect a failure (e.g. an order exceeding stock being
          rejected), set expect_error_json on the When step instead of binding
          a Then to the error.
        - Assertions on probe steps: assert_json supports {"equals": x},
          {"equals_ref": "$cap.field"}, {"contains": x}, {"count": n},
          {"gt"/"gte"/"lt"/"lte": n}, {"not_null": true}, {"is_null": true},
          plus an optional {"path": "dot.path"} into the probe result.
        TXT;
    }

    /** The user-turn prompt: the Gherkin plus the operation catalog inline. */
    public function userPrompt(string $gherkin): string
    {
        $catalog = [];
        foreach ($this->registry->granted() as $spec) {
            $params = $spec->parameters === []
                ? '(no parameters)'
                : implode('; ', array_map(
                    fn (string $name, string $description): string => "{$name}: {$description}",
                    array_keys($spec->parameters),
                    array_values($spec->parameters),
                ));
            $catalog[] = "- [{$spec->kind}] {$spec->key} — {$spec->summary}\n  params: {$params}";
        }

        return "Compile this Gherkin scenario into an execution plan.\n\n"
            ."AVAILABLE OPERATIONS:\n".implode("\n", $catalog)."\n\n"
            ."GHERKIN:\n```gherkin\n".$gherkin."\n```";
    }

    /**
     * {@inheritDoc}
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'steps' => $schema->array()->items(
                $schema->object(fn (JsonSchema $s) => [
                    'keyword' => $s->string()->enum(['given', 'when', 'then'])->required(),
                    'text' => $s->string()->description('The original Gherkin step text.')->required(),
                    'op' => $s->string()->description('An operation key from the catalog.')->required(),
                    'args_json' => $s->string()->description('JSON object of operation arguments ("{}" when none).')->required(),
                    'capture' => $s->string()->nullable()->description('snake_case variable name to store the result under.'),
                    'assert_json' => $s->string()->nullable()->description('JSON assertion object for probe steps.'),
                    'expect_error_json' => $s->string()->nullable()->description('JSON {"class": "...", "message_contains": "..."} when this step must fail.'),
                ])
            )->required(),
            'unbound' => $schema->array()->items(
                $schema->object(fn (JsonSchema $s) => [
                    'step_text' => $s->string()->required(),
                    'suggested_operation' => $s->string()->description('The operation key likely needed, e.g. action:App\\Actions\\Orders\\DeleteOrderAction.')->required(),
                    'why' => $s->string()->nullable(),
                ])
            )->required(),
        ];
    }

    public function messages(): iterable
    {
        return [];
    }

    public function tools(): iterable
    {
        return [
            app(ListOperationsTool::class),
            app(DescribeOperationTool::class),
        ];
    }

    /**
     * Decode the structured output into a runnable plan + unbound list.
     *
     * @param  array<string, mixed>  $structured
     * @return array{plan: array<string, mixed>, unbound: list<array<string, string|null>>, errors: list<string>}
     */
    public function toPlan(array $structured): array
    {
        $steps = [];
        $errors = [];

        foreach (array_values((array) ($structured['steps'] ?? [])) as $index => $raw) {
            if (! is_array($raw)) {
                continue;
            }
            $label = 'Step '.($index + 1);

            $decode = function (string $field) use ($raw, $label, &$errors): ?array {
                $value = $raw[$field] ?? null;
                if ($value === null || $value === '' || $value === 'null') {
                    return null;
                }
                $decoded = json_decode((string) $value, true);
                if (! is_array($decoded)) {
                    $errors[] = "{$label}: {$field} is not valid JSON.";

                    return null;
                }

                return $decoded;
            };

            $steps[] = array_filter([
                'keyword' => (string) ($raw['keyword'] ?? 'given'),
                'text' => (string) ($raw['text'] ?? ''),
                'op' => (string) ($raw['op'] ?? ''),
                'args' => $decode('args_json') ?? [],
                'capture' => isset($raw['capture']) && $raw['capture'] !== '' ? (string) $raw['capture'] : null,
                'assert' => $decode('assert_json'),
                'expect_error' => $decode('expect_error_json'),
            ], fn ($value) => $value !== null);
        }

        $unbound = [];
        foreach ((array) ($structured['unbound'] ?? []) as $entry) {
            if (is_array($entry) && isset($entry['suggested_operation'])) {
                $unbound[] = [
                    'step_text' => (string) ($entry['step_text'] ?? ''),
                    'suggested_operation' => (string) $entry['suggested_operation'],
                    'why' => isset($entry['why']) ? (string) $entry['why'] : null,
                ];
            }
        }

        return [
            'plan' => ['version' => 1, 'steps' => $steps],
            'unbound' => $unbound,
            'errors' => $errors,
        ];
    }
}
