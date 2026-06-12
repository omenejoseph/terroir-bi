<?php

declare(strict_types=1);

namespace App\Services\Ai\Agents;

use App\Services\Bdd\OperationRegistry;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

/**
 * Compiler tool: list every operation the scenario may use (GRANTED only —
 * fail-closed). Returns static metadata; never touches tenant data.
 */
class ListOperationsTool implements Tool
{
    public function __construct(private readonly OperationRegistry $registry) {}

    public function description(): Stringable|string
    {
        return 'List the operations available to BDD plans: built-in seed.* (Given), probe.* (Then) and granted action:* (When) keys with one-line summaries.';
    }

    public function handle(Request $request): Stringable|string
    {
        $lines = [];
        foreach ($this->registry->granted() as $spec) {
            $lines[] = "[{$spec->kind}] {$spec->key} — {$spec->summary}";
        }

        return implode("\n", $lines);
    }

    /**
     * {@inheritDoc}
     */
    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
