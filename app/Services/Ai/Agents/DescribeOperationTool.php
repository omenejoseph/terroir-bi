<?php

declare(strict_types=1);

namespace App\Services\Ai\Agents;

use App\Services\Bdd\OperationRegistry;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

/**
 * Compiler tool: full parameter description for one granted operation (names,
 * types, $ref expectations, auto-filled operator ids). Static metadata only.
 */
class DescribeOperationTool implements Tool
{
    public function __construct(private readonly OperationRegistry $registry) {}

    public function description(): Stringable|string
    {
        return 'Describe one operation by key (e.g. "seed.inventory_item" or "action:App\\Actions\\Orders\\CreateOrderAction"): its parameters, which must be $capture references, and which are auto-filled.';
    }

    public function handle(Request $request): Stringable|string
    {
        $key = (string) $request->all()['key'];
        $spec = $this->registry->describe($key);

        if ($spec === null) {
            return "Operation [{$key}] is not available (not granted or unknown). If the scenario needs it, report it in `unbound`.";
        }

        $lines = ["{$spec->key} ({$spec->kind}) — {$spec->summary}", 'Parameters:'];
        foreach ($spec->parameters as $name => $description) {
            $lines[] = "  - {$name}: {$description}";
        }
        if ($spec->parameters === []) {
            $lines[] = '  (none)';
        }

        return implode("\n", $lines);
    }

    /**
     * {@inheritDoc}
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'key' => $schema->string()->description('The operation key to describe.')->required(),
        ];
    }
}
