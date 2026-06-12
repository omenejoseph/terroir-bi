<?php

declare(strict_types=1);

namespace App\Services\Bdd;

use App\Models\BddOperationGrant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use RuntimeException;
use Symfony\Component\Finder\Finder;

/**
 * The catalog of operations BDD plans may use, and the fail-closed access gate.
 *
 * Three kinds:
 *  - seed.*   built-in Given primitives (always available)
 *  - probe.*  built-in read-only Then primitives (always available)
 *  - action:<FQCN>  domain Actions — EXPLICIT grant per class, never implicit.
 *
 * Guard rails: a hard, non-overridable blocklist keeps money/identity/platform
 * surfaces out of reach even if a grant row exists for them; the AI compiler
 * only ever sees granted operations, and the runner re-checks at execution.
 */
class OperationRegistry
{
    /** Namespaces that can NEVER be granted (checked at grant AND run time). */
    private const BLOCKED_NAMESPACES = [
        'App\\Actions\\Billing\\',
        'App\\Actions\\Tenancy\\',
        'App\\Actions\\Auth\\',
        'App\\Actions\\Ai\\',
        'App\\Actions\\Bdd\\',
        'App\\Actions\\Notifications\\',
        'App\\Actions\\Invitations\\',
    ];

    public const ACTION_PREFIX = 'action:';

    /**
     * Shape hints for array parameters whose structure can't be reflected (a
     * bare `array` type tells the model nothing). Keyed by "Class::param".
     * Without these the model guesses the order-line shape and builds a custom
     * line that deducts no stock.
     */
    private const ARRAY_PARAM_HINTS = [
        'App\\Actions\\Orders\\CreateOrderAction::data' => 'object. items: list of order lines [{inventory_item_id: a captured item id like "$r3.id"; quantity: integer; '
            .'unit_type: the item\'s sales unit ("bottles" or "cases"); unit_price?: integer MINOR units (omit to use the '
            .'customer/catalog price); custom_description?: string for a non-product line}]. Optional: notes (string), '
            .'is_backorder (bool — skips stock deduction), is_consignment (bool), status (RECEIVED|IN_PROCESS|READY_TO_SHIP|SHIPPED), '
            .'shipping_cost (integer minor units). Do NOT put customer here — it is the separate customer parameter.',
        'App\\Actions\\Orders\\AddOrderItemsAction::items' => 'list of order lines [{inventory_item_id: a captured item id like "$r3.id"; quantity: integer; '
            .'unit_type: the item\'s sales unit ("bottles" or "cases"); unit_price?: integer minor units; custom_description?: string}].',
    ];

    /**
     * A top-level string parameter that identifies the acting operator/user
     * (createdById, userId, changedById, actorId, grantedById, triggeredById,
     * authorId, performedById…). The runner auto-fills these with the sandbox
     * admin, so the model neither sees nor supplies them.
     */
    public static function isOperatorIdParam(?string $typeName, string $name): bool
    {
        return $typeName === 'string' && preg_match('/Id$/', $name) === 1;
    }

    /**
     * Canonicalise an operation key's prefix separator. Models routinely conflate
     * the two conventions — seeds/probes use a dot (`probe.stock_of`) while
     * actions use a colon (`action:App\…`) — emitting `probe:stock_of` or
     * `action.App\…`. Left as-is, the wrong separator reads as an unknown,
     * ungranted operation and would park the step as needing access.
     */
    public static function canonicalKey(string $op): string
    {
        if (preg_match('/^(seed|probe)[:.](.+)$/', $op, $m) === 1) {
            return $m[1].'.'.$m[2];
        }

        if (preg_match('/^action[:.](.+)$/', $op, $m) === 1) {
            return self::ACTION_PREFIX.$m[1];
        }

        return $op;
    }

    /** Whether a key is a built-in (grant-free) seed/probe. */
    public function isBuiltIn(string $key): bool
    {
        return str_starts_with($key, 'seed.') || str_starts_with($key, 'probe.');
    }

    /** Hard blocklist — non-overridable, independent of grant rows. */
    public function isBlocked(string $key): bool
    {
        if (! str_starts_with($key, self::ACTION_PREFIX)) {
            return false;
        }

        $class = substr($key, strlen(self::ACTION_PREFIX));

        foreach (self::BLOCKED_NAMESPACES as $namespace) {
            if (str_starts_with($class, $namespace)) {
                return true;
            }
        }

        return ! str_starts_with($class, 'App\\Actions\\');
    }

    public function isGranted(string $key): bool
    {
        if ($this->isBuiltIn($key)) {
            return true;
        }

        if ($this->isBlocked($key)) {
            return false;
        }

        return BddOperationGrant::query()->where('operation_key', $key)->exists();
    }

    /** Throws unless the key may be granted (used by the grant action). */
    public function assertGrantable(string $key): void
    {
        if ($this->isBuiltIn($key)) {
            throw new RuntimeException('Built-in seed/probe operations are always available — no grant needed.');
        }

        if ($this->isBlocked($key)) {
            throw new RuntimeException("Guard rail: [{$key}] is on the hard blocklist and can never be granted.");
        }

        $class = substr($key, strlen(self::ACTION_PREFIX));

        if (! class_exists($class) || ! method_exists($class, 'execute')) {
            throw new RuntimeException("Unknown operation [{$key}] — not a discoverable action.");
        }
    }

    /**
     * Everything the AI compiler and runner may currently use.
     *
     * @return list<OperationSpec>
     */
    public function granted(): array
    {
        $specs = [...SeedOperations::specs(), ...ProbeOperations::specs()];

        $grants = BddOperationGrant::query()->pluck('operation_key');
        foreach ($grants as $key) {
            $key = (string) $key;
            if ($this->isBlocked($key) || ! str_starts_with($key, self::ACTION_PREFIX)) {
                continue; // defense in depth: ignore rogue rows
            }
            $spec = $this->describeAction(substr($key, strlen(self::ACTION_PREFIX)));
            if ($spec !== null) {
                $specs[] = $spec;
            }
        }

        return $specs;
    }

    /**
     * Discoverable actions that are NOT yet granted — what the compiler may
     * REQUEST (by exact key) in `unbound`, so it never has to guess a class
     * name. Built-ins are never here (they're always available).
     *
     * @return list<OperationSpec>
     */
    public function requestableActions(): array
    {
        $grantedKeys = BddOperationGrant::query()->pluck('operation_key')->flip();

        return array_values(array_filter(
            $this->discoverActions(),
            fn (OperationSpec $spec): bool => ! isset($grantedKeys[$spec->key]),
        ));
    }

    /** Whether a key names a real, grantable (discoverable, non-blocked) action. */
    public function isRequestableAction(string $key): bool
    {
        if ($this->isBuiltIn($key) || $this->isBlocked($key) || ! str_starts_with($key, self::ACTION_PREFIX)) {
            return false;
        }

        $class = substr($key, strlen(self::ACTION_PREFIX));

        return class_exists($class) && method_exists($class, 'execute');
    }

    public function describe(string $key): ?OperationSpec
    {
        foreach ($this->granted() as $spec) {
            if ($spec->key === $key) {
                return $spec;
            }
        }

        return null;
    }

    /**
     * Every action class that COULD be granted (for the admin Access page) —
     * discovered by scanning app/Actions, minus the blocklist.
     *
     * @return list<OperationSpec>
     */
    public function discoverActions(): array
    {
        $specs = [];

        foreach (Finder::create()->files()->in(app_path('Actions'))->name('*.php') as $file) {
            $class = 'App\\Actions\\'.str_replace(
                ['/', '.php'],
                ['\\', ''],
                (string) $file->getRelativePathname(),
            );

            $key = self::ACTION_PREFIX.$class;

            if ($this->isBlocked($key) || ! class_exists($class) || ! method_exists($class, 'execute')) {
                continue;
            }

            $spec = $this->describeAction($class);
            if ($spec !== null) {
                $specs[] = $spec;
            }
        }

        usort($specs, fn (OperationSpec $a, OperationSpec $b) => strcmp($a->key, $b->key));

        return $specs;
    }

    /**
     * Discoverable actions paired with their current grant state — the single
     * read the access page needs (so the Filament page touches no DB itself).
     *
     * @return list<array<string, mixed>>
     */
    public function discoverActionsWithGrants(): array
    {
        $granted = BddOperationGrant::query()->pluck('operation_key')->flip();

        return array_map(
            fn (OperationSpec $spec): array => $spec->toArray() + ['granted' => isset($granted[$spec->key])],
            $this->discoverActions(),
        );
    }

    /**
     * The built-in (grant-free) seed/probe operations, for reference display.
     *
     * @return list<array<string, mixed>>
     */
    public function builtInSpecs(): array
    {
        return array_map(
            fn (OperationSpec $spec): array => $spec->toArray(),
            [...SeedOperations::specs(), ...ProbeOperations::specs()],
        );
    }

    /**
     * Reflect an action's execute() into an OperationSpec: the class docblock
     * becomes the summary; each parameter is described by name + type, with
     * model-typed params flagged as $ref captures and *Id strings flagged as
     * auto-filled with the sandbox operator where conventional.
     */
    public function describeAction(string $class): ?OperationSpec
    {
        if (! class_exists($class) || ! method_exists($class, 'execute')) {
            return null;
        }

        $reflection = new ReflectionClass($class);
        $method = $reflection->getMethod('execute');

        $summary = $this->docSummary($reflection) ?? Str::headline(class_basename($class));

        $parameters = [];
        foreach ($method->getParameters() as $parameter) {
            $type = $parameter->getType();
            $name = $parameter->getName();

            if ($type instanceof ReflectionNamedType && ! $type->isBuiltin() && is_subclass_of($type->getName(), Model::class)) {
                $parameters[$name] = '$ref to a captured '.class_basename($type->getName())
                    .' (entity reference — raw ids are rejected)'
                    .($parameter->allowsNull() ? ', or null' : ', required');

                continue;
            }

            $typeName = $type instanceof ReflectionNamedType ? $type->getName() : 'mixed';

            // Operator-id params (createdById, userId, changedById, actorId…)
            // are auto-filled with the sandbox operator at run time — hide them
            // from the model entirely so it never supplies a (possibly blank or
            // hallucinated) value.
            if (self::isOperatorIdParam($typeName, $name)) {
                continue;
            }

            if ($type instanceof ReflectionNamedType && ! $type->isBuiltin() && enum_exists($typeName)) {
                $cases = array_map(
                    fn ($case) => $case instanceof \BackedEnum ? (string) $case->value : $case->name,
                    $typeName::cases(),
                );
                $parameters[$name] = 'enum: '.implode('|', $cases).($parameter->isOptional() ? ' (optional)' : ' (required)');

                continue;
            }

            // A curated shape for array params that can't be reflected.
            $hint = self::ARRAY_PARAM_HINTS[$class.'::'.$name] ?? null;
            if ($hint !== null) {
                $parameters[$name] = $hint.($parameter->isOptional() ? ' (optional)' : ' (required)');

                continue;
            }

            $parameters[$name] = $typeName
                .($parameter->allowsNull() ? '|null' : '')
                .($parameter->isOptional() ? ' (optional)' : ' (required)');
        }

        return new OperationSpec(
            key: self::ACTION_PREFIX.$class,
            kind: 'action',
            summary: $summary,
            parameters: $parameters,
            requiresGrant: true,
        );
    }

    /**
     * First sentence(s) of a class docblock, stripped of comment syntax.
     *
     * @param  ReflectionClass<object>  $reflection
     */
    private function docSummary(ReflectionClass $reflection): ?string
    {
        $doc = $reflection->getDocComment();
        if ($doc === false) {
            return null;
        }

        $lines = [];
        foreach (preg_split('/\R/', $doc) ?: [] as $line) {
            $line = trim($line, " \t/*");
            if ($line === '' || str_starts_with($line, '@')) {
                if ($lines !== []) {
                    break;
                }

                continue;
            }
            $lines[] = $line;
        }

        return $lines === [] ? null : implode(' ', $lines);
    }

    /**
     * The reflected execute() method for the runner's argument binding.
     *
     * @param  class-string  $class
     */
    public function executeMethod(string $class): ReflectionMethod
    {
        return (new ReflectionClass($class))->getMethod('execute');
    }
}
