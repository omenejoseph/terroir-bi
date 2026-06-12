<?php

declare(strict_types=1);

namespace App\Services\Bdd;

use App\Authorization\MembershipContext;
use App\Enums\BddRunStatus;
use App\Enums\MembershipStatus;
use App\Enums\TenantRole;
use App\Enums\TenantStatus;
use App\Models\BddScenario;
use App\Models\BddScenarioRun;
use App\Models\Membership;
use App\Models\Tenant;
use App\Models\TenantSetting;
use App\Models\User;
use App\Support\Money\Money;
use App\Tenancy\Contracts\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Replays a compiled BDD plan inside an ALWAYS-ROLLED-BACK transaction against
 * a throwaway sandbox tenant created for the run. No AI is involved at run
 * time — the plan is deterministic.
 *
 * Guard rails (see also OperationRegistry/PlanValidator/SandboxContext):
 *  - one beginTransaction … finally rollBack wraps the entire run, pass or
 *    fail, so the sandbox tenant and everything the steps created vanish;
 *  - every operation is grant-checked again at execution (fail-closed);
 *  - model arguments resolve ONLY from this run's captures and are verified to
 *    belong to the sandbox before any invocation;
 *  - the tenant context is re-asserted after every step;
 *  - a post-run leak check confirms the sandbox row is gone.
 */
class ScenarioRunner
{
    public function __construct(
        private readonly OperationRegistry $registry,
        private readonly PlanValidator $validator,
        private readonly TenantContext $tenantContext,
        private readonly MembershipContext $membershipContext,
    ) {}

    public function run(BddScenario $scenario, ?string $triggeredById = null): BddScenarioRun
    {
        $startedAt = hrtime(true);
        $stepResults = [];
        $status = BddRunStatus::Error;
        $error = null;

        $previousTenant = $this->tenantContext->current();
        $previousMembership = $this->membershipContext->current();
        $sandboxTenantId = null;

        $plan = $scenario->compiled_plan;

        if (! is_array($plan) || ! $scenario->status->isRunnable()) {
            return $this->persist($scenario, BddRunStatus::Error, [], 'The scenario has no runnable compiled plan.', $startedAt, $triggeredById);
        }

        // Re-validate against TODAY's grants — a revoked grant or renamed
        // action parks the run as NEEDS_ACCESS instead of exploding.
        $validation = $this->validator->validate($plan);
        if ($validation['ungranted'] !== []) {
            return $this->persist(
                $scenario,
                BddRunStatus::NeedsAccess,
                [],
                'Operations need access: '.implode(', ', $validation['ungranted']),
                $startedAt,
                $triggeredById,
            );
        }
        if ($validation['errors'] !== []) {
            return $this->persist($scenario, BddRunStatus::Error, [], implode(' ', $validation['errors']), $startedAt, $triggeredById);
        }

        DB::beginTransaction();

        try {
            $sandbox = $this->createSandbox();
            $sandboxTenantId = $sandbox->tenant->getKey();

            $this->tenantContext->makeCurrent($sandbox->tenant);
            $membership = Membership::query()
                ->where('tenant_id', $sandbox->tenant->getKey())
                ->where('user_id', $sandbox->admin->getKey())
                ->firstOrFail();
            $this->membershipContext->set($membership);

            $seeds = new SeedOperations($sandbox);
            $probes = new ProbeOperations($sandbox);
            $captures = [];
            $failed = false;

            /** @var list<array<string, mixed>> $steps */
            $steps = $plan['steps'];

            foreach ($steps as $index => $step) {
                $result = $this->executeStep($step, $sandbox, $seeds, $probes, $captures);
                $result['index'] = $index + 1;
                $result['keyword'] = (string) ($step['keyword'] ?? '');
                $result['text'] = (string) ($step['text'] ?? '');
                $result['op'] = (string) ($step['op'] ?? '');
                $stepResults[] = $result;

                // Guard rail: an operation must not have swapped the tenant context.
                if ($this->tenantContext->currentId() !== (string) $sandbox->tenant->getKey()) {
                    throw new RuntimeException('Guard rail: a step mutated the tenant context — run aborted.');
                }

                if ($result['status'] !== 'pass') {
                    $failed = true;
                    break;
                }
            }

            $status = $failed ? BddRunStatus::Fail : BddRunStatus::Pass;
        } catch (Throwable $e) {
            $status = BddRunStatus::Error;
            $error = $e->getMessage();
        } finally {
            DB::rollBack();

            // Restore whatever contexts were bound before the run.
            if ($previousTenant !== null) {
                $this->tenantContext->makeCurrent($previousTenant);
            } else {
                $this->tenantContext->forget();
            }
            if ($previousMembership !== null) {
                $this->membershipContext->set($previousMembership);
            } else {
                $this->membershipContext->forget();
            }
        }

        // Guard rail: leak check — the sandbox must have vanished with the rollback.
        if ($sandboxTenantId !== null && Tenant::query()->whereKey($sandboxTenantId)->exists()) {
            $status = BddRunStatus::Error;
            $error = trim(($error ?? '').' Guard rail: sandbox tenant survived the rollback — investigate immediately.');
        }

        return $this->persist($scenario, $status, $stepResults, $error, $startedAt, $triggeredById);
    }

    /**
     * @param  array<string, mixed>  $step
     * @param  array<string, mixed>  $captures
     * @return array<string, mixed>
     */
    private function executeStep(array $step, SandboxContext $sandbox, SeedOperations $seeds, ProbeOperations $probes, array &$captures): array
    {
        $op = (string) ($step['op'] ?? '');
        $stepStart = hrtime(true);

        // Defense in depth — the validator already checked, but never trust a plan.
        if (! $this->registry->isGranted($op)) {
            return ['status' => 'needs_access', 'detail' => "Operation [{$op}] is not granted."];
        }

        try {
            $rawArgs = $step['args'] ?? [];
            $args = $this->interpolate(is_array($rawArgs) ? $rawArgs : [], $captures, $sandbox);

            $expectError = $step['expect_error'] ?? null;

            try {
                $output = $this->invoke($op, $args, $sandbox, $seeds, $probes);
            } catch (Throwable $e) {
                if (is_array($expectError)) {
                    return $this->matchExpectedError($e, $expectError, $stepStart);
                }

                throw $e;
            }

            if (is_array($expectError)) {
                return [
                    'status' => 'fail',
                    'detail' => 'Expected '.(string) ($expectError['class'] ?? 'an error').' but the operation succeeded.',
                    'duration_ms' => $this->elapsedMs($stepStart),
                ];
            }

            $capture = $step['capture'] ?? null;
            if (is_string($capture) && $capture !== '') {
                $captures[$capture] = $output;
            }

            if (isset($step['assert']) && is_array($step['assert'])) {
                return $this->evaluateAssertion($output, $step['assert'], $captures) + [
                    'duration_ms' => $this->elapsedMs($stepStart),
                ];
            }

            return [
                'status' => 'pass',
                'detail' => $this->summarise($output),
                'duration_ms' => $this->elapsedMs($stepStart),
            ];
        } catch (Throwable $e) {
            return [
                'status' => 'error',
                'detail' => $e->getMessage(),
                'duration_ms' => $this->elapsedMs($stepStart),
            ];
        }
    }

    /**
     * @param  array<string, mixed>  $args
     */
    private function invoke(string $op, array $args, SandboxContext $sandbox, SeedOperations $seeds, ProbeOperations $probes): mixed
    {
        if (str_starts_with($op, 'seed.')) {
            return $seeds->execute($op, $args);
        }

        if (str_starts_with($op, 'probe.')) {
            return $probes->execute($op, $args);
        }

        $class = substr($op, strlen(OperationRegistry::ACTION_PREFIX));

        if (! class_exists($class)) {
            throw new InvalidArgumentException("Unknown action [{$class}].");
        }

        $method = $this->registry->executeMethod($class);

        $bound = [];
        foreach ($method->getParameters() as $parameter) {
            $name = $parameter->getName();
            $type = $parameter->getType();
            $typeName = $type instanceof \ReflectionNamedType ? $type->getName() : null;
            $isOperatorId = OperationRegistry::isOperatorIdParam($typeName, $name);

            if (array_key_exists($name, $args)) {
                $value = $args[$name];

                // Operator-id params are the sandbox operator's — never the
                // model's. Honour a resolved sandbox-user $ref, but fall back to
                // the admin for a blank/placeholder value (the model sometimes
                // emits an empty createdById, which would break the FK).
                if ($isOperatorId) {
                    $bound[$name] = (is_string($value) && trim($value) !== '')
                        ? $value
                        : (string) $sandbox->admin->getKey();

                    continue;
                }

                // Guard rail: model parameters must arrive as resolved captures.
                if ($typeName !== null && is_subclass_of($typeName, Model::class)) {
                    if (! $value instanceof $typeName) {
                        throw new InvalidArgumentException("Parameter [{$name}] must be a \$ref to a captured ".class_basename($typeName).'.');
                    }
                    $sandbox->assertOwned($value);
                } elseif ($typeName !== null && is_subclass_of($typeName, \BackedEnum::class) && is_string($value)) {
                    $value = $typeName::from($value);
                }

                $bound[$name] = $value;

                continue;
            }

            // Conventional operator id params auto-fill with the sandbox admin.
            if ($isOperatorId) {
                $bound[$name] = (string) $sandbox->admin->getKey();

                continue;
            }

            if ($parameter->isDefaultValueAvailable()) {
                $bound[$name] = $parameter->getDefaultValue();

                continue;
            }

            if ($parameter->allowsNull()) {
                $bound[$name] = null;

                continue;
            }

            throw new InvalidArgumentException("Missing required parameter [{$name}] for [{$class}].");
        }

        return app($class)->execute(...$bound);
    }

    /**
     * Resolve $capture references inside args. `$name` yields the captured
     * value itself (a model/array); `$name.path` digs into attributes/keys.
     *
     * @param  array<array-key, mixed>  $args
     * @param  array<string, mixed>  $captures
     * @return array<array-key, mixed>
     */
    private function interpolate(array $args, array $captures, SandboxContext $sandbox): array
    {
        $resolve = function (mixed $value) use (&$resolve, $captures, $sandbox): mixed {
            if (is_array($value)) {
                return array_map($resolve, $value);
            }

            if (! is_string($value) || ! str_starts_with($value, '$')) {
                return $value;
            }

            [$root, $path] = array_pad(explode('.', substr($value, 1), 2), 2, null);

            if (! array_key_exists((string) $root, $captures)) {
                throw new InvalidArgumentException("Unknown reference \${$root}.");
            }

            $resolved = $captures[(string) $root];

            if ($path !== null) {
                $resolved = $this->dig($resolved, (string) $path);
            } elseif ($resolved instanceof Model) {
                $sandbox->assertOwned($resolved);
            }

            return $resolved;
        };

        return array_map($resolve, $args);
    }

    private function dig(mixed $value, string $path): mixed
    {
        foreach (explode('.', $path) as $segment) {
            if ($value instanceof Model) {
                $value = $segment === 'id' ? $value->getKey() : $value->getAttribute($segment);
            } elseif (is_array($value)) {
                $value = $value[$segment] ?? null;
            } elseif (is_object($value)) {
                $value = $value->{$segment} ?? null;
            } else {
                return null;
            }

            // Money values compare in integer minor units.
            if ($value instanceof Money) {
                $value = $value->getMinorAmount();
            }
            if ($value instanceof \BackedEnum) {
                $value = $value->value;
            }
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $assert
     * @param  array<string, mixed>  $captures
     * @return array{status: string, detail: string}
     */
    private function evaluateAssertion(mixed $output, array $assert, array $captures): array
    {
        $actual = isset($assert['path']) ? $this->dig($output, (string) $assert['path']) : $output;

        if ($actual instanceof Money) {
            $actual = $actual->getMinorAmount();
        }

        $check = function (string $operator, mixed $expected) use ($actual): bool {
            return match ($operator) {
                'equals' => $this->looselyEquals($actual, $expected),
                'contains' => is_string($actual)
                    ? str_contains($actual, (string) $expected)
                    : (is_array($actual) && in_array($expected, $actual)),
                'count' => (is_countable($actual) ? count($actual) : null) === (int) $expected,
                'gt' => is_numeric($actual) && (float) $actual > (float) $expected,
                'gte' => is_numeric($actual) && (float) $actual >= (float) $expected,
                'lt' => is_numeric($actual) && (float) $actual < (float) $expected,
                'lte' => is_numeric($actual) && (float) $actual <= (float) $expected,
                'not_null' => $actual !== null,
                'is_null' => $actual === null,
                default => false,
            };
        };

        foreach ($assert as $operator => $expected) {
            if ($operator === 'path') {
                continue;
            }

            if ($operator === 'equals_ref') {
                $expected = is_string($expected) && str_starts_with($expected, '$')
                    ? $this->dig($captures[explode('.', substr($expected, 1), 2)[0]] ?? null, explode('.', substr($expected, 1), 2)[1] ?? '')
                    : $expected;
                $operator = 'equals';
            }

            if (! $check($operator, $expected)) {
                return [
                    'status' => 'fail',
                    'detail' => "Expected {$operator} ".json_encode($expected).' but got '.json_encode($actual).'.',
                ];
            }
        }

        return ['status' => 'pass', 'detail' => 'Asserted '.json_encode($actual).'.'];
    }

    private function looselyEquals(mixed $actual, mixed $expected): bool
    {
        if (is_numeric($actual) && is_numeric($expected)) {
            return abs((float) $actual - (float) $expected) < 0.0001;
        }

        return $actual === $expected;
    }

    /**
     * @param  array<string, mixed>  $expectError
     * @return array{status: string, detail: string, duration_ms: int}
     */
    private function matchExpectedError(Throwable $e, array $expectError, int $stepStart): array
    {
        $wantedClass = isset($expectError['class']) ? (string) $expectError['class'] : null;
        $wantedMessage = isset($expectError['message_contains']) ? (string) $expectError['message_contains'] : null;

        $classMatches = $wantedClass === null
            || $e::class === $wantedClass
            || class_basename($e) === class_basename($wantedClass);
        $messageMatches = $wantedMessage === null || str_contains($e->getMessage(), $wantedMessage);

        if ($classMatches && $messageMatches) {
            return [
                'status' => 'pass',
                'detail' => 'Got expected error: '.class_basename($e).' — '.Str::limit($e->getMessage(), 160),
                'duration_ms' => $this->elapsedMs($stepStart),
            ];
        }

        return [
            'status' => 'fail',
            'detail' => 'Error mismatch: got '.class_basename($e).' "'.Str::limit($e->getMessage(), 120).'"',
            'duration_ms' => $this->elapsedMs($stepStart),
        ];
    }

    /** Create the throwaway tenant + operator INSIDE the run's transaction. */
    private function createSandbox(): SandboxContext
    {
        $suffix = strtolower((string) Str::ulid());

        $tenant = Tenant::create([
            'name' => 'BDD Sandbox',
            'slug' => 'bdd-sandbox-'.$suffix,
            'status' => TenantStatus::Active,
        ]);

        TenantSetting::create([
            'tenant_id' => $tenant->getKey(),
            'default_currency' => 'EUR',
            'default_locale' => 'hr',
        ]);

        $admin = User::create([
            'first_name' => 'BDD',
            'last_name' => 'Runner',
            'email' => 'bdd-runner-'.$suffix.'@sandbox.test',
            'password' => Str::random(40),
        ]);

        Membership::create([
            'tenant_id' => $tenant->getKey(),
            'user_id' => $admin->getKey(),
            'roles' => collect([TenantRole::Admin]),
            'status' => MembershipStatus::Active,
            'joined_at' => now(),
        ]);

        return new SandboxContext($tenant, $admin);
    }

    private function summarise(mixed $output): string
    {
        if ($output instanceof Model) {
            return class_basename($output).' created';
        }
        if (is_array($output)) {
            return Str::limit((string) json_encode($output), 200);
        }

        return Str::limit((string) json_encode($output), 200);
    }

    private function elapsedMs(int $since): int
    {
        return (int) ((hrtime(true) - $since) / 1_000_000);
    }

    /**
     * Persist the run AFTER the rollback so the record survives.
     *
     * @param  list<array<string, mixed>>  $stepResults
     */
    private function persist(
        BddScenario $scenario,
        BddRunStatus $status,
        array $stepResults,
        ?string $error,
        int $startedAt,
        ?string $triggeredById,
    ): BddScenarioRun {
        $run = BddScenarioRun::create([
            'bdd_scenario_id' => $scenario->getKey(),
            'status' => $status,
            'step_results' => $stepResults,
            'error' => $error,
            'duration_ms' => $this->elapsedMs($startedAt),
            'triggered_by_id' => $triggeredById,
        ]);

        $scenario->update([
            'last_run_status' => $status,
            'last_run_at' => now(),
        ]);

        return $run;
    }
}
