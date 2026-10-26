<?php

declare(strict_types=1);

namespace App\Services\Onboarding;

use App\Enums\OnboardingStepStatus;
use Illuminate\Contracts\Support\Arrayable;

/**
 * One row of the setup checklist, as the /setup hub renders it. Title, copy
 * and link live in resources/js/lib/onboarding.ts, keyed by `key` — this side
 * only reports where the step stands.
 *
 * @implements Arrayable<string, mixed>
 */
final class OnboardingStep implements Arrayable
{
    /**
     * @param  array{done: int, total: int}|null  $progress  "n of m" for steps measured across many rows
     * @param  list<array{message: string, count: int}>  $issues  data-quality nudges; `message` is a translation key taking :count
     */
    public function __construct(
        public readonly string $key,
        public readonly OnboardingStepStatus $status,
        public readonly ?array $progress = null,
        public readonly ?int $count = null,
        public readonly array $issues = [],
        public readonly bool $skippable = false,
        public readonly bool $markable = false,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'status' => $this->status->value,
            'progress' => $this->progress,
            'count' => $this->count,
            'issues' => $this->issues,
            'skippable' => $this->skippable,
            'markable' => $this->markable,
        ];
    }
}
