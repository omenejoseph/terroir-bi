<?php

declare(strict_types=1);

namespace App\Services\Bdd\Tools;

use App\Services\Bdd\LiveExecutionContext;
use App\Services\Bdd\OperationRegistry;
use App\Services\Bdd\ProbeOperations;
use App\Tenancy\Contracts\TenantContext;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Laravel\Ai\Tools\Request;
use Stringable;

/**
 * Reads real state from the sandbox database via a built-in probe.* operation.
 * The returned JSON is the GROUND TRUTH every Then judgement must cite — the
 * probe itself carries no verdict.
 */
class ProbeTool extends BddTool
{
    public function __construct(
        LiveExecutionContext $context,
        TenantContext $tenantContext,
        private readonly ProbeOperations $probes,
    ) {
        parent::__construct($context, $tenantContext);
    }

    public function name(): string
    {
        return 'probe';
    }

    public function description(): Stringable|string
    {
        return 'Read real data from the sandbox database via a probe.* operation. Returns the observed '
            .'value as JSON. ALWAYS probe before judging a Then line — judge only what a probe returned.';
    }

    /**
     * {@inheritDoc}
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'op' => $schema->string()->description('A probe.* operation key from the catalog.')->required(),
            'args_json' => $schema->string()->description('JSON object of probe arguments ("{}" when none).')->required(),
        ];
    }

    protected function run(Request $request): string
    {
        $op = OperationRegistry::canonicalKey((string) $request->string('op'));

        if (! str_starts_with($op, 'probe.')) {
            return "Error: `probe` only runs probe.* operations (got [{$op}]).";
        }

        $args = $this->decodeArgs($request['args_json'] ?? null);
        if ($args === null) {
            return 'Error: args_json is not valid JSON — send a JSON object.';
        }

        try {
            $result = $this->probes->execute($op, $this->context->interpolate($args));
        } catch (InvalidArgumentException $e) {
            return 'Error: '.$e->getMessage();
        } catch (\Throwable $e) {
            $this->context->recordStep([
                'keyword' => 'then',
                'status' => 'error',
                'op' => $op,
                'detail' => $e->getMessage(),
            ]);

            return "The probe failed unexpectedly ({$e->getMessage()}). This is recorded as an infrastructure error — call `finish` now.";
        }

        $json = (string) json_encode($result);

        $this->context->recordProbe($result);
        $this->context->recordStep([
            'keyword' => 'then',
            'status' => 'info',
            'op' => $op,
            'text' => 'Observed via '.$op,
            'detail' => Str::limit($json, 500),
        ]);

        return $json;
    }
}
