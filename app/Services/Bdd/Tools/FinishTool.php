<?php

declare(strict_types=1);

namespace App\Services\Bdd\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Tools\Request;
use Stringable;

/**
 * Ends the run. The verdict is NOT taken from the model's summary — it is
 * aggregated in code from the recorded steps (see LiveExecutionContext) — so
 * finishing early or with an optimistic summary cannot turn a run green.
 */
class FinishTool extends BddTool
{
    public function name(): string
    {
        return 'finish';
    }

    public function description(): Stringable|string
    {
        return 'End the run once every Then line has been judged (or an unrecoverable problem was reported).';
    }

    /**
     * {@inheritDoc}
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'summary' => $schema->string()->description('Optional one-line summary of the run.'),
        ];
    }

    protected function run(Request $request): string
    {
        $this->context->markFinished();

        return 'Run finished. Reply with a single short closing sentence — do not call more tools.';
    }
}
