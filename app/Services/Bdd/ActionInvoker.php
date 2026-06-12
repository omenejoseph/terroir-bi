<?php

declare(strict_types=1);

namespace App\Services\Bdd;

use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * Binds interpolated arguments onto a granted action's execute() via reflection
 * and invokes it. Guard rails: model parameters accept ONLY captured sandbox
 * entities (verified with assertOwned), and operator-id parameters are always
 * the sandbox admin's — never a value the AI made up.
 */
class ActionInvoker
{
    public function __construct(private readonly OperationRegistry $registry) {}

    /**
     * @param  class-string  $class
     * @param  array<string, mixed>  $args  interpolated args ($refs resolved)
     * @param  array<string, mixed>  $rawArgs  the pre-interpolation args (raw $refs)
     * @param  array<string, mixed>  $captures
     */
    public function invoke(string $class, array $args, array $rawArgs, array $captures, SandboxContext $sandbox): mixed
    {
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
                // A model param always wants the ENTITY, so accept the capture in
                // whatever form the model emitted it — "$customer" (the entity)
                // or "$customer.id" (it reached for a foreign key it doesn't
                // need); both recover the same captured Customer.
                if ($typeName !== null && is_subclass_of($typeName, Model::class)) {
                    if (! $value instanceof $typeName) {
                        $value = $this->captureRootEntity($rawArgs[$name] ?? null, $captures, $typeName);
                    }
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
     * The captured entity behind a "$root.path" reference, when its root capture
     * is of the wanted type. Lets a model param tolerate "$customer.id" (or any
     * "$customer.field") by recovering the captured Customer the root names.
     *
     * @param  array<string, mixed>  $captures
     * @param  class-string<Model>  $typeName
     */
    private function captureRootEntity(mixed $rawValue, array $captures, string $typeName): ?Model
    {
        if (! is_string($rawValue) || ! str_starts_with($rawValue, '$')) {
            return null;
        }

        $root = explode('.', substr($rawValue, 1), 2)[0];
        $candidate = $captures[$root] ?? null;

        return $candidate instanceof $typeName ? $candidate : null;
    }
}
