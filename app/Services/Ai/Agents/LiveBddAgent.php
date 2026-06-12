<?php

declare(strict_types=1);

namespace App\Services\Ai\Agents;

use App\Services\Bdd\OperationRegistry;
use App\Services\Bdd\OperationSpec;
use App\Services\Bdd\Tools\BddTool;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Promptable;
use Stringable;

/**
 * Executes a Gherkin BDD scenario LIVE by calling tools against a sandboxed,
 * always-rolled-back database transaction. Unlike the retired compile-and-
 * replay model, every tool result feeds straight back into the loop, so the
 * model sees real outcomes (and real error messages) and self-corrects within
 * the run instead of failing later on a stale plan.
 *
 * The model "sees" the application only through the operation CATALOG inlined
 * into the prompt and the tool results — never raw tenant data. It is a pure
 * tool-calling agent (no structured output): structured output combined with a
 * tool loop is rejected by some gateway providers (Gemini), and the verdict is
 * aggregated in code from the recorded steps anyway, so no output schema is
 * needed.
 *
 * The tools are run-scoped instances sharing one LiveExecutionContext, so the
 * agent is constructed per run (never resolved from the container).
 */
class LiveBddAgent implements Agent, Conversational, HasTools
{
    use Promptable;

    /**
     * @param  list<BddTool>  $tools
     */
    public function __construct(
        private readonly OperationRegistry $registry,
        private readonly array $tools,
    ) {}

    public function instructions(): Stringable|string
    {
        return <<<'TXT'
        You execute Gherkin BDD scenarios LIVE against a wine business
        management application (orders, inventory, customers, consignment) by
        calling tools. The full catalog of available operations — every seed,
        probe and granted action with its parameters — is provided in the
        prompt. Use only what is listed there.

        Execution rules — follow them exactly:
        - Work ONLY through tool calls. Never describe what you would do.
        - Each Given line → the `given` tool (a seed.* operation). Each When
          line → the `when` tool (an action:* operation). Each Then line →
          FIRST the `probe` tool (read the real database state), THEN the
          `then` tool (your judgement citing the probed value). And/But lines
          continue the previous keyword.
        - Judge every Then ONLY against values a probe actually returned —
          never from expectation or memory. Cite the observed value verbatim.
        - Add any PREREQUISITE seeds the Gherkin leaves implicit. Actions need
          supporting entities — e.g. creating an order requires a customer and
          the inventory item(s) being ordered; seed them first with `given`.
        - Reference entities ONLY via capture names: "$name" (the entity) or
          "$name.field" (an attribute, e.g. "$r3.id"). NEVER literal database
          ids. Every "$name" must have been captured by an earlier tool call.
        - All money is integer minor units (€12.00 → 1200). Stock quantities in
          seeds are numeric strings in the item's storage unit.
        - Action parameters named like createdById are auto-filled with the
          sandbox operator — omit them.
        - For a When that must FAIL (e.g. an order exceeding stock being
          rejected), pass expect_error_message_contains — a short, distinctive
          substring of the error message you expect (e.g. "not enough stock").
        - If a tool returns a message starting with "Error:", fix your
          arguments and call it again — that feedback is there for you.
        - If `when` reports an operation is NOT granted, do not retry it —
          call `finish` immediately.
        - When every Then line has been judged, call `finish`.
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

        // Ungranted actions, by exact key. Calling one via `when` records the
        // access request for the admin (and the run parks as NEEDS_ACCESS) —
        // this is what stops the model guessing class names.
        $requestable = array_map(
            fn (OperationSpec $spec): string => "- {$spec->key} — {$spec->summary}",
            $this->registry->requestableActions(),
        );

        $requestableBlock = $requestable === []
            ? '(none — every discoverable action is already granted)'
            : implode("\n", $requestable);

        return "Execute this Gherkin scenario now by calling tools.\n\n"
            ."AVAILABLE OPERATIONS:\n".implode("\n", $available)."\n\n"
            ."ACTIONS NOT YET GRANTED (if a When step needs one, call `when` once with its EXACT key so the request is recorded, then call `finish`):\n".$requestableBlock."\n\n"
            ."GHERKIN:\n```gherkin\n".$gherkin."\n```";
    }

    /**
     * {@inheritDoc}
     */
    public function tools(): iterable
    {
        return $this->tools;
    }

    /** Iteration cap for the tool loop — a runaway model cannot spin forever. */
    public function maxSteps(): int
    {
        return 40;
    }

    public function messages(): iterable
    {
        return [];
    }
}
