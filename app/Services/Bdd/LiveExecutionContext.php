<?php

declare(strict_types=1);

namespace App\Services\Bdd;

use App\Enums\BddRunStatus;
use App\Support\Money\Money;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * The mutable state one live BDD run accumulates while the AI agent calls
 * tools: entity captures, the authoritative step results, denied operations
 * and the full tool transcript. All tools of a run share ONE instance, executed
 * synchronously inside the run's open (always-rolled-back) transaction — so a
 * plain in-process object is enough, nothing is serialized.
 *
 * The verdict is aggregated here IN CODE: infrastructure errors, denied
 * operations and "the AI judged nothing" can never be papered over by the
 * model — only the semantic pass/fail of each Then is the model's call.
 */
class LiveExecutionContext
{
    /** @var array<string, mixed> */
    private array $captures = [];

    /** @var list<array<string, mixed>> */
    private array $steps = [];

    /** @var list<string> */
    private array $denied = [];

    /** @var list<array<string, mixed>> */
    private array $transcript = [];

    private mixed $lastProbe = null;

    private bool $probed = false;

    private bool $finished = false;

    private ?int $stepStartedAt = null;

    public function __construct(public readonly SandboxContext $sandbox) {}

    // --- Captures --------------------------------------------------------------

    public function capture(string $name, mixed $value): void
    {
        $this->captures[$name] = $value;
    }

    /**
     * @return array<string, mixed>
     */
    public function captures(): array
    {
        return $this->captures;
    }

    /**
     * Resolve $capture references inside args. `$name` yields the captured
     * value itself (a model/array); `$name.path` digs into attributes/keys.
     * Guard rails: unknown references and raw entity-id literals throw — the
     * tool returns the message to the model so it can self-correct.
     *
     * @param  array<array-key, mixed>  $args
     * @return array<array-key, mixed>
     */
    public function interpolate(array $args): array
    {
        $resolve = function (mixed $value) use (&$resolve): mixed {
            if (is_array($value)) {
                return array_map($resolve, $value);
            }

            if (! is_string($value)) {
                return $value;
            }

            if (! str_starts_with($value, '$')) {
                if ($this->looksLikeEntityId($value)) {
                    throw new InvalidArgumentException(
                        'Raw entity ids are not allowed (guard rail) — reference entities via a $capture name.',
                    );
                }

                return $value;
            }

            [$root, $path] = array_pad(explode('.', substr($value, 1), 2), 2, null);

            if (! array_key_exists((string) $root, $this->captures)) {
                throw new InvalidArgumentException("Unknown reference \${$root} — nothing has been captured under that name.");
            }

            $resolved = $this->captures[(string) $root];

            if ($path !== null) {
                $resolved = $this->dig($resolved, (string) $path);
            } elseif ($resolved instanceof Model) {
                $this->sandbox->assertOwned($resolved);
            }

            return $resolved;
        };

        return array_map($resolve, $args);
    }

    private function dig(mixed $value, string $path): mixed
    {
        // Accept array-index notation (`[0]`, `items[2].quantity`) alongside dot
        // paths by normalising brackets to dots; PHP maps the numeric-string key
        // back to the list's integer index on access.
        $normalised = str_replace(['[', ']'], ['.', ''], $path);

        foreach (array_filter(explode('.', $normalised), static fn (string $s): bool => $s !== '') as $segment) {
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

    /** ULIDs (26 Crockford chars) and UUIDs — the shapes our entity ids take. */
    private function looksLikeEntityId(string $value): bool
    {
        return preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/i', $value) === 1
            || preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $value) === 1;
    }

    // --- Step results ------------------------------------------------------------

    /** Tools call this when handling starts, so recorded steps carry a duration. */
    public function startStepTimer(): void
    {
        $this->stepStartedAt = hrtime(true);
    }

    /**
     * Record one authoritative step-result row (status: pass|fail|error|info|needs_access).
     *
     * @param  array<string, mixed>  $row
     */
    public function recordStep(array $row): void
    {
        $row['index'] = count($this->steps) + 1;
        if ($this->stepStartedAt !== null) {
            $row['duration_ms'] = (int) ((hrtime(true) - $this->stepStartedAt) / 1_000_000);
        }

        $this->steps[] = $row;
    }

    public function recordDenied(string $op): void
    {
        if (! in_array($op, $this->denied, true)) {
            $this->denied[] = $op;
        }
    }

    public function recordProbe(mixed $result): void
    {
        $this->lastProbe = $result;
        $this->probed = true;
    }

    public function hasProbed(): bool
    {
        return $this->probed;
    }

    public function lastProbe(): mixed
    {
        return $this->lastProbe;
    }

    public function markFinished(): void
    {
        $this->finished = true;
    }

    public function isFinished(): bool
    {
        return $this->finished;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function stepResults(): array
    {
        return $this->steps;
    }

    /**
     * @return list<string>
     */
    public function deniedOperations(): array
    {
        return $this->denied;
    }

    // --- Transcript ----------------------------------------------------------------

    /**
     * Append one tool invocation (or the final assistant text) to the audit
     * transcript persisted with the run.
     *
     * @param  array<string, mixed>  $entry
     */
    public function recordTranscript(array $entry): void
    {
        $this->transcript[] = $entry;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function transcript(): array
    {
        return $this->transcript;
    }

    // --- Verdict -------------------------------------------------------------------

    /**
     * Aggregate the run's verdict from the recorded steps. Infrastructure
     * errors, missing grants and an absent Then judgement are decided here in
     * code; only each Then's pass/fail came from the model.
     */
    public function verdict(): BddRunStatus
    {
        if ($this->denied !== []) {
            return BddRunStatus::NeedsAccess;
        }

        if ($this->statusRecorded('error')) {
            return BddRunStatus::Error;
        }

        if ($this->statusRecorded('fail')) {
            return BddRunStatus::Fail;
        }

        if (! $this->thenRecorded()) {
            return BddRunStatus::Error;
        }

        return BddRunStatus::Pass;
    }

    /** The human-readable explanation matching verdict(), when one is needed. */
    public function error(): ?string
    {
        if ($this->denied !== []) {
            return 'Operations need access: '.implode(', ', $this->denied);
        }

        foreach ($this->steps as $step) {
            if (($step['status'] ?? '') === 'error') {
                return 'Step '.($step['index'] ?? '?').' errored: '.((string) ($step['detail'] ?? ''));
            }
        }

        if ($this->statusRecorded('fail')) {
            return null;
        }

        if (! $this->thenRecorded()) {
            return 'The AI recorded no Then judgement — the scenario was never actually verified.';
        }

        return null;
    }

    private function statusRecorded(string $status): bool
    {
        foreach ($this->steps as $step) {
            if (($step['status'] ?? '') === $status) {
                return true;
            }
        }

        return false;
    }

    private function thenRecorded(): bool
    {
        foreach ($this->steps as $step) {
            if (($step['keyword'] ?? '') === 'then') {
                return true;
            }
        }

        return false;
    }
}
