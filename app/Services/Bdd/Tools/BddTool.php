<?php

declare(strict_types=1);

namespace App\Services\Bdd\Tools;

use App\Services\Bdd\LiveExecutionContext;
use App\Tenancy\Contracts\TenantContext;
use Illuminate\Support\Str;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use RuntimeException;
use Stringable;

/**
 * Base for the live BDD run's tools. Every call re-asserts the sandbox tenant
 * context FIRST (guard rail — a mutated context aborts the whole run as an
 * Error by throwing out of the agent loop), and every call + result is
 * appended to the run's audit transcript.
 *
 * Recoverable problems (a bad $reference, malformed args) must be RETURNED as
 * descriptive strings, never thrown: the string goes back to the model as the
 * tool result, which is exactly the in-loop feedback that lets it self-correct.
 */
abstract class BddTool implements Tool
{
    public function __construct(
        protected readonly LiveExecutionContext $context,
        protected readonly TenantContext $tenantContext,
    ) {}

    abstract public function name(): string;

    abstract protected function run(Request $request): string;

    public function handle(Request $request): Stringable|string
    {
        // Guard rail: nothing may have swapped the tenant context away from the
        // sandbox. A mismatch is non-recoverable — abort the entire run.
        if ($this->tenantContext->currentId() !== (string) $this->context->sandbox->tenant->getKey()) {
            throw new RuntimeException('Guard rail: the tenant context was mutated mid-run — run aborted.');
        }

        $this->context->startStepTimer();
        $this->context->log('▶ '.$this->name().' '.Str::limit((string) json_encode($request->toArray()), 180));

        try {
            $result = $this->run($request);
        } catch (\Throwable $e) {
            $this->context->log('✖ '.$this->name().' aborted the run: '.$e->getMessage());
            $this->context->recordTranscript([
                'tool' => $this->name(),
                'arguments' => $request->toArray(),
                'exception' => $e->getMessage(),
            ]);

            throw $e;
        }

        $this->context->log('← '.Str::limit($result, 240));
        $this->context->recordTranscript([
            'tool' => $this->name(),
            'arguments' => $request->toArray(),
            'result' => $result,
        ]);

        return $result;
    }

    /**
     * Decode an args payload the model sent. Declared as a JSON string in the
     * schema, but models often return the nested object directly, or wrap the
     * JSON in ``` fences — accept all three. Null means undecodable.
     *
     * @return array<string, mixed>|null
     */
    protected function decodeArgs(mixed $value): ?array
    {
        if ($value === null || $value === '' || $value === 'null' || $value === '{}' || $value === []) {
            return [];
        }

        if (is_array($value)) {
            return $value;
        }

        if (! is_string($value)) {
            return null;
        }

        $clean = trim($value);
        if (str_starts_with($clean, '```')) {
            $clean = (string) preg_replace('/^```[a-zA-Z]*\s*|\s*```$/', '', $clean);
            $clean = trim($clean);
        }

        $decoded = json_decode($clean, true);

        return is_array($decoded) ? $decoded : null;
    }
}
