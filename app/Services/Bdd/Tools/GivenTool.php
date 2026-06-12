<?php

declare(strict_types=1);

namespace App\Services\Bdd\Tools;

use App\Services\Bdd\LiveExecutionContext;
use App\Services\Bdd\OperationRegistry;
use App\Services\Bdd\SeedOperations;
use App\Tenancy\Contracts\TenantContext;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use InvalidArgumentException;
use Laravel\Ai\Tools\Request;
use Stringable;

/**
 * Executes one Given line: a built-in seed.* operation creating a sandbox
 * entity, captured under a name for later $references.
 */
class GivenTool extends BddTool
{
    public function __construct(
        LiveExecutionContext $context,
        TenantContext $tenantContext,
        private readonly SeedOperations $seeds,
    ) {
        parent::__construct($context, $tenantContext);
    }

    public function name(): string
    {
        return 'given';
    }

    public function description(): Stringable|string
    {
        return 'Execute a Given step: run a seed.* operation to create a supporting entity '
            .'(inventory item, customer, …) in the sandbox and capture it under a name.';
    }

    /**
     * {@inheritDoc}
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'text' => $schema->string()->description('The Gherkin Given line this seed fulfils.')->required(),
            'op' => $schema->string()->description('A seed.* operation key from the catalog.')->required(),
            'args_json' => $schema->string()->description('JSON object of seed arguments ("{}" when none).')->required(),
            'capture' => $schema->string()->description('snake_case name to store the created entity under, for later "$name" references.')->required(),
        ];
    }

    protected function run(Request $request): string
    {
        $op = OperationRegistry::canonicalKey((string) $request->string('op'));

        if (! str_starts_with($op, 'seed.')) {
            return "Error: `given` only runs seed.* operations (got [{$op}]). Use `when` for actions and `probe` for observations.";
        }

        $args = $this->decodeArgs($request['args_json'] ?? null);
        if ($args === null) {
            return 'Error: args_json is not valid JSON — send a JSON object.';
        }

        try {
            $entity = $this->seeds->execute($op, $this->context->interpolate($args));
        } catch (InvalidArgumentException $e) {
            // An argument mistake — feed it back so the model corrects the call.
            return 'Error: '.$e->getMessage();
        } catch (\Throwable $e) {
            $this->context->recordStep([
                'keyword' => 'given',
                'status' => 'error',
                'op' => $op,
                'text' => (string) $request->string('text'),
                'detail' => $e->getMessage(),
            ]);

            return "The seed failed unexpectedly ({$e->getMessage()}). This is recorded as an infrastructure error — call `finish` now.";
        }

        $capture = trim((string) $request->string('capture'));
        if ($capture === '') {
            return 'Error: provide a snake_case `capture` name so the entity can be referenced later.';
        }

        $this->context->capture($capture, $entity);
        $this->context->recordStep([
            'keyword' => 'given',
            'status' => 'pass',
            'op' => $op,
            'text' => (string) $request->string('text'),
            'detail' => class_basename($entity).' created, captured as $'.$capture.'.',
        ]);

        return 'OK: '.class_basename($entity).' created and captured as $'.$capture.'.';
    }
}
