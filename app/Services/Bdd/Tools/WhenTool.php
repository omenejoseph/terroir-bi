<?php

declare(strict_types=1);

namespace App\Services\Bdd\Tools;

use App\Services\Bdd\ActionInvoker;
use App\Services\Bdd\LiveExecutionContext;
use App\Services\Bdd\OperationRegistry;
use App\Tenancy\Contracts\TenantContext;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Laravel\Ai\Tools\Request;
use Stringable;

/**
 * Executes one When line: a granted action:* operation against the sandbox.
 * The grant check is fail-closed and re-done here at call time; a miss is
 * recorded as a needs_access step (the run parks as NEEDS_ACCESS) instead of
 * executing anything. Expected-failure steps pass expect_error_message_contains
 * and are matched factually in code — an exception is never the model's to
 * reinterpret.
 */
class WhenTool extends BddTool
{
    public function __construct(
        LiveExecutionContext $context,
        TenantContext $tenantContext,
        private readonly OperationRegistry $registry,
        private readonly ActionInvoker $invoker,
    ) {
        parent::__construct($context, $tenantContext);
    }

    public function name(): string
    {
        return 'when';
    }

    public function description(): Stringable|string
    {
        return 'Execute a When step: invoke a granted action:* operation. For a step that MUST fail '
            .'(e.g. an overdraw being rejected), pass expect_error_message_contains with a short '
            .'distinctive substring of the expected error message.';
    }

    /**
     * {@inheritDoc}
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'text' => $schema->string()->description('The Gherkin When line this action fulfils.')->required(),
            'op' => $schema->string()->description('An action:* operation key from the catalog (exact key).')->required(),
            'args_json' => $schema->string()->description('JSON object of action arguments ("{}" when none). Reference entities as "$capture" / "$capture.field".')->required(),
            'capture' => $schema->string()->description('Optional snake_case name to store the action result under.'),
            'expect_error_message_contains' => $schema->string()->description('Only when this step must FAIL: a short plain substring expected in the error message.'),
        ];
    }

    protected function run(Request $request): string
    {
        $op = OperationRegistry::canonicalKey((string) $request->string('op'));

        if (! str_starts_with($op, OperationRegistry::ACTION_PREFIX)) {
            return "Error: `when` only runs action:* operations (got [{$op}]). Use `given` for seeds and `probe` for observations.";
        }

        // Guard rail: fail-closed grant check at call time (blocklist included).
        if (! $this->registry->isGranted($op)) {
            $this->context->recordDenied($op);
            $this->context->recordStep([
                'keyword' => 'when',
                'status' => 'needs_access',
                'op' => $op,
                'text' => (string) $request->string('text'),
                'detail' => "Operation [{$op}] is not granted.",
            ]);

            return "Operation [{$op}] is NOT granted. It has been recorded as an access request for the admin — do NOT retry it; call `finish` now.";
        }

        $rawArgs = $this->decodeArgs($request['args_json'] ?? null);
        if ($rawArgs === null) {
            return 'Error: args_json is not valid JSON — send a JSON object.';
        }

        try {
            $args = $this->context->interpolate($rawArgs);
        } catch (InvalidArgumentException $e) {
            return 'Error: '.$e->getMessage();
        }

        $expectError = trim((string) $request->string('expect_error_message_contains'));
        $expectError = $expectError === '' ? null : $expectError;
        $class = substr($op, strlen(OperationRegistry::ACTION_PREFIX));
        if (! class_exists($class)) {
            return "Error: unknown action [{$class}] — use an exact action:* key from the catalog.";
        }
        $text = (string) $request->string('text');

        try {
            $output = $this->invoker->invoke($class, $args, $rawArgs, $this->context->captures(), $this->context->sandbox);
        } catch (\Throwable $e) {
            return $this->handleThrown($e, $op, $text, $expectError);
        }

        if ($expectError !== null) {
            $this->context->recordStep([
                'keyword' => 'when',
                'status' => 'fail',
                'op' => $op,
                'text' => $text,
                'detail' => "Expected an error containing \"{$expectError}\" but the action succeeded.",
            ]);

            return "FAIL (recorded): you expected an error containing \"{$expectError}\" but the action SUCCEEDED. "
                .'Probe the resulting state, then judge the remaining Then lines honestly.';
        }

        $detail = class_basename($class).' executed.';
        $capture = trim((string) $request->string('capture'));
        if ($capture !== '') {
            $this->context->capture($capture, $output);
            $detail .= ' Result captured as $'.$capture.'.';
        }

        $this->context->recordStep([
            'keyword' => 'when',
            'status' => 'pass',
            'op' => $op,
            'text' => $text,
            'detail' => $detail,
        ]);

        return 'OK: '.$detail;
    }

    /** Factual handling of a thrown action — never thrown back, never AI-reinterpreted. */
    private function handleThrown(\Throwable $e, string $op, string $text, ?string $expectError): string
    {
        $summary = class_basename($e).' — '.Str::limit($e->getMessage(), 200);

        if ($expectError !== null) {
            $matched = mb_stripos($e->getMessage(), $expectError) !== false;

            // A non-matching argument-binding mistake is still the model's to
            // fix, not a verdict — only a real, different domain error is.
            if (! $matched && $e instanceof InvalidArgumentException) {
                return 'Error: '.$e->getMessage();
            }

            $this->context->recordStep([
                'keyword' => 'when',
                'status' => $matched ? 'pass' : 'fail',
                'op' => $op,
                'text' => $text,
                'detail' => $matched
                    ? 'Got expected error: '.$summary
                    : "Error mismatch: expected a message containing \"{$expectError}\" but got {$summary}",
            ]);

            return $matched
                ? "OK: the action was rejected as expected ({$summary})."
                : "FAIL (recorded): the action threw {$summary}, which does not contain \"{$expectError}\". Continue with the remaining steps.";
        }

        // An argument-binding mistake (missing param, wrong $ref shape) is the
        // model's to fix — feed it back without recording a step.
        if ($e instanceof InvalidArgumentException) {
            return 'Error: '.$e->getMessage();
        }

        $this->context->recordStep([
            'keyword' => 'when',
            'status' => 'error',
            'op' => $op,
            'text' => $text,
            'detail' => $summary,
        ]);

        return "The action failed unexpectedly ({$summary}). This is recorded as an error in code — "
            .'if the Gherkin expected this failure, you should have passed expect_error_message_contains; otherwise call `finish` now.';
    }
}
