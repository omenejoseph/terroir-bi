<?php

declare(strict_types=1);

namespace App\Services\Bdd;

/**
 * Structural validation of a compiled plan before it is saved or replayed.
 *
 * Plan shape:
 *   { "version": 1, "steps": [
 *       { "keyword": "given|when|then", "text": "...", "op": "seed.x|probe.y|action:FQCN",
 *         "args": {...}, "capture": "name"|null,
 *         "assert": {...}|null, "expect_error": {"class": "...", "message_contains": "..."}|null }
 *   ]}
 *
 * Guard rails enforced here:
 *  - every op must be granted (fail-closed; ungranted ops are reported so the
 *    scenario can park in NEEDS_ACCESS),
 *  - $refs must point at captures defined by EARLIER steps,
 *  - raw ULID/UUID-looking literals are rejected anywhere in args — entities
 *    may only enter a plan through captures, never by id,
 *  - step cap.
 */
class PlanValidator
{
    public const MAX_STEPS = 30;

    private const ASSERT_OPERATORS = ['equals', 'equals_ref', 'contains', 'count', 'gt', 'gte', 'lt', 'lte', 'not_null', 'is_null'];

    public function __construct(private readonly OperationRegistry $registry) {}

    /**
     * @param  array<string, mixed>  $plan
     * @return array{errors: list<string>, ungranted: list<string>}
     */
    public function validate(array $plan): array
    {
        $errors = [];
        $ungranted = [];

        $steps = $plan['steps'] ?? null;
        if (! is_array($steps) || $steps === []) {
            return ['errors' => ['The plan has no steps.'], 'ungranted' => []];
        }

        if (count($steps) > self::MAX_STEPS) {
            $errors[] = 'The plan exceeds the maximum of '.self::MAX_STEPS.' steps.';
        }

        $captures = [];

        foreach (array_values($steps) as $index => $step) {
            $label = 'Step '.($index + 1);

            if (! is_array($step)) {
                $errors[] = "{$label}: not an object.";

                continue;
            }

            $keyword = (string) ($step['keyword'] ?? '');
            if (! in_array($keyword, ['given', 'when', 'then'], true)) {
                $errors[] = "{$label}: keyword must be given|when|then.";
            }

            $op = (string) ($step['op'] ?? '');
            if ($op === '') {
                $errors[] = "{$label}: missing op.";

                continue;
            }

            if ($this->registry->isBlocked($op)) {
                $errors[] = "{$label}: operation [{$op}] is blocklisted and can never run.";
            } elseif (! $this->registry->isGranted($op)) {
                $ungranted[] = $op;
            }

            // Probes never mutate; only they may carry assertions.
            if (isset($step['assert']) && ! str_starts_with($op, 'probe.')) {
                $errors[] = "{$label}: only probe.* steps may assert.";
            }

            if (isset($step['assert']) && is_array($step['assert'])) {
                $operators = array_intersect(array_keys($step['assert']), self::ASSERT_OPERATORS);
                if ($operators === []) {
                    $errors[] = "{$label}: assert must use one of: ".implode(', ', self::ASSERT_OPERATORS).'.';
                }
            }

            $args = $step['args'] ?? [];
            if (! is_array($args)) {
                $errors[] = "{$label}: args must be an object.";
                $args = [];
            }

            foreach ($this->collectStrings($args) as $value) {
                if (str_starts_with($value, '$')) {
                    $root = explode('.', substr($value, 1), 2)[0];
                    if (! isset($captures[$root])) {
                        $errors[] = "{$label}: references \${$root} before any step captured it.";
                    }
                } elseif ($this->looksLikeEntityId($value)) {
                    $errors[] = "{$label}: raw entity ids are not allowed in plans (guard rail) — use a \$capture reference.";
                }
            }

            // equals_ref assertions also dereference captures.
            $ref = $step['assert']['equals_ref'] ?? null;
            if (is_string($ref) && str_starts_with($ref, '$')) {
                $root = explode('.', substr($ref, 1), 2)[0];
                if (! isset($captures[$root])) {
                    $errors[] = "{$label}: equals_ref references \${$root} before any step captured it.";
                }
            }

            $capture = $step['capture'] ?? null;
            if (is_string($capture) && $capture !== '') {
                $captures[$capture] = true;
            }
        }

        return ['errors' => $errors, 'ungranted' => array_values(array_unique($ungranted))];
    }

    /**
     * @param  array<array-key, mixed>  $args
     * @return list<string>
     */
    private function collectStrings(array $args): array
    {
        $out = [];
        array_walk_recursive($args, function ($value) use (&$out): void {
            if (is_string($value)) {
                $out[] = $value;
            }
        });

        return $out;
    }

    /** ULIDs (26 Crockford chars) and UUIDs — the shapes our entity ids take. */
    private function looksLikeEntityId(string $value): bool
    {
        return preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/i', $value) === 1
            || preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $value) === 1;
    }
}
