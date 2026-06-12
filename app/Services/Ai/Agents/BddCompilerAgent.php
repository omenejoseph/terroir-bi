<?php

declare(strict_types=1);

namespace App\Services\Ai\Agents;

use App\Services\Bdd\OperationRegistry;
use App\Services\Bdd\OperationSpec;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;
use Stringable;

/**
 * Compiles a Gherkin BDD scenario into a deterministic execution plan bound to
 * the application's exposed (granted) operations. The model "sees" the
 * application through the operation CATALOG inlined into the prompt — every
 * granted seed/probe/action with its parameters — never through tenant data,
 * and it must never invent operations: a step it cannot bind goes into
 * `unbound` so the admin can grant access (fail-closed).
 *
 * Why the catalog is inlined rather than fetched via live tool-calls: structured
 * output + an interactive tool loop is rejected by some gateway providers
 * (Gemini returns 400 "Required value missing: contents" on the tool-result
 * turn). Feeding the full catalog up front is provider-portable, cheaper, and
 * gives the model the same information in one round-trip — matching the proven
 * structured-output extractor pattern (DocumentExtractor).
 *
 * Nested free-form values (args/assert/expect_error) travel as JSON-encoded
 * strings to keep the structured-output schema strict; ScenarioCompiler
 * decodes and validates them.
 */
class BddCompilerAgent implements Agent, Conversational, HasStructuredOutput
{
    use Promptable;

    public function __construct(private readonly OperationRegistry $registry) {}

    public function instructions(): Stringable|string
    {
        return <<<'TXT'
        You compile Gherkin BDD scenarios into executable JSON plans for a wine
        business management application (orders, inventory, customers,
        consignment). The full catalog of available operations — every seed,
        probe and granted action with its parameters — is provided to you in the
        prompt. Use only what is listed there.

        Binding rules — follow them exactly:
        - Each Given step binds to a seed.* operation, each When step to an
          action:* operation, each Then step to a probe.* operation with an
          assertion. And/But continue the previous keyword.
        - Bind ONLY to operations under "AVAILABLE OPERATIONS". seed.* and
          probe.* are ALWAYS available — they must NEVER appear in `unbound`.
        - If a When step needs an action that is NOT under "AVAILABLE
          OPERATIONS", look it up under "REQUESTABLE ACTIONS" and put its EXACT
          key in `unbound` (copy the key verbatim — never invent or guess a
          class name, and never put a seed/probe there). Still emit every step
          you CAN bind.
        - Entities are referenced by $capture variables: give a step a short
          snake_case `capture` name and reference it later as "$name" (the
          entity itself) or "$name.field" (an attribute, e.g. "$r3.id").
          NEVER write literal database ids. EVERY "$name" you reference MUST be
          captured by an EARLIER step.
        - Add any PREREQUISITE seeds the Gherkin leaves implicit. Actions need
          supporting entities — e.g. creating an order requires a customer and
          the inventory item(s) being ordered. If the scenario doesn't spell
          them out, add the seed.* Given steps to create them first, then
          reference their captures. (An order step always needs a $customer and
          at least one inventory item.)
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
        $describe = function (OperationSpec $spec): string {
            $params = $spec->parameters === []
                ? '(no parameters)'
                : implode('; ', array_map(
                    fn (string $name, string $description): string => "{$name}: {$description}",
                    array_keys($spec->parameters),
                    array_values($spec->parameters),
                ));

            return "- [{$spec->kind}] {$spec->key} — {$spec->summary}\n  params: {$params}";
        };

        $available = array_map($describe, $this->registry->granted());

        // Ungranted actions, by exact key (no params — they can only be
        // REQUESTED, not bound, until granted). This is what stops the model
        // guessing class names for the `unbound` list.
        $requestable = array_map(
            fn (OperationSpec $spec): string => "- {$spec->key} — {$spec->summary}",
            $this->registry->requestableActions(),
        );

        $requestableBlock = $requestable === []
            ? '(none — every discoverable action is already granted)'
            : implode("\n", $requestable);

        return "Compile this Gherkin scenario into an execution plan.\n\n"
            ."AVAILABLE OPERATIONS (bind steps to these):\n".implode("\n", $available)."\n\n"
            ."REQUESTABLE ACTIONS (NOT yet granted — if a When step needs one, copy its exact key into `unbound`; never bind it):\n".$requestableBlock."\n\n"
            ."GHERKIN:\n```gherkin\n".$gherkin."\n```";
    }

    /**
     * A self-correction turn: re-issue the same compile with the validator's
     * complaints about the previous attempt, so the model fixes them (most
     * often a missing prerequisite seed or an undefined $reference).
     *
     * @param  list<string>  $errors
     */
    public function retryPrompt(string $gherkin, string $previousPlanJson, array $errors): string
    {
        return $this->userPrompt($gherkin)."\n\n"
            .'YOUR PREVIOUS ATTEMPT WAS INVALID. Fix exactly these problems and '
            .'return a corrected plan (add any missing prerequisite seed steps, '
            ."and make sure every \$reference is captured by an earlier step):\n"
            .'- '.implode("\n- ", $errors)."\n\n"
            ."PREVIOUS PLAN:\n".$previousPlanJson;
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
                    'suggested_operation' => $s->string()->description('An EXACT key copied from REQUESTABLE ACTIONS (an action:* key). Never a seed/probe, never a guessed name.')->required(),
                    'why' => $s->string()->nullable(),
                ])
            )->required(),
        ];
    }

    public function messages(): iterable
    {
        return [];
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

            // The *_json fields are declared as strings in the schema, but models
            // (esp. via structured output) often return the nested object
            // directly, or wrap the JSON in ``` fences. Accept all three.
            $decode = function (string $field) use ($raw, $label, &$errors): ?array {
                $value = $raw[$field] ?? null;

                if ($value === null || $value === '' || $value === 'null' || $value === []) {
                    return null;
                }

                // Model returned the object/array directly — use it as-is.
                if (is_array($value)) {
                    return $value;
                }

                if (! is_string($value)) {
                    $errors[] = "{$label}: {$field} is not valid JSON.";

                    return null;
                }

                $clean = trim($value);
                if (str_starts_with($clean, '```')) {
                    $clean = (string) preg_replace('/^```[a-zA-Z]*\s*|\s*```$/', '', $clean);
                    $clean = trim($clean);
                }

                $decoded = json_decode($clean, true);
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
