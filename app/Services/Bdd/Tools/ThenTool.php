<?php

declare(strict_types=1);

namespace App\Services\Bdd\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Tools\Request;
use Stringable;

/**
 * Records the model's judgement of one Then line — the ONLY place the verdict
 * is the AI's call. Code enforces that at least one probe ran first, so the
 * judgement is anchored to real database state, and the cited observation +
 * reasoning are persisted for auditing. A "green" here is still the model's
 * claim — the transcript is what makes it checkable.
 */
class ThenTool extends BddTool
{
    public function name(): string
    {
        return 'then';
    }

    public function description(): Stringable|string
    {
        return 'Judge one Then line of the scenario: state whether the expectation holds, citing the '
            .'exact value a probe returned. Run a probe FIRST — judgements without an observation are rejected.';
    }

    /**
     * {@inheritDoc}
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'text' => $schema->string()->description('The Gherkin Then line being judged.')->required(),
            'observed' => $schema->string()->description('The exact value(s) you read from the probe result that this judgement rests on.')->required(),
            'passed' => $schema->boolean()->description('Whether the expectation holds against the observed value.')->required(),
            'reason' => $schema->string()->description('One sentence: why the observed value does or does not satisfy the expectation.')->required(),
        ];
    }

    protected function run(Request $request): string
    {
        if (! $this->context->hasProbed()) {
            return 'Error: judge nothing from memory — run a `probe` first and cite its observed value.';
        }

        $passed = (bool) $request['passed'];
        $text = (string) $request->string('text');

        $this->context->recordStep([
            'keyword' => 'then',
            'status' => $passed ? 'pass' : 'fail',
            'op' => 'ai.judgement',
            'text' => $text,
            'detail' => 'Observed: '.$request->string('observed').' — '.$request->string('reason'),
        ]);

        return ($passed ? 'Recorded as PASSED' : 'Recorded as FAILED').": {$text}. Continue with the remaining steps, or call `finish` when every Then is judged.";
    }
}
