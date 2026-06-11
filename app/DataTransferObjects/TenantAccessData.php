<?php

declare(strict_types=1);

namespace App\DataTransferObjects;

use App\Enums\AccessLevel;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Support\Carbon;
use JsonSerializable;

/**
 * The computed access state for a tenant: how much of the app it may use right
 * now and the relevant billing milestones. Produced by
 * App\Services\Billing\SubscriptionAccessService and surfaced on /auth/me.
 *
 * @implements Arrayable<string, mixed>
 */
final class TenantAccessData implements Arrayable, JsonSerializable
{
    public function __construct(
        public readonly AccessLevel $level,
        /** A short machine reason: the Stripe status, or 'trial' / 'no_subscription' / 'suspended' / 'free'. */
        public readonly string $status,
        public readonly ?Carbon $trialEndsAt = null,
        public readonly ?Carbon $currentPeriodEnd = null,
        public readonly ?Carbon $graceFullUntil = null,
        public readonly ?Carbon $graceReadonlyUntil = null,
        /** Whole days until the next transition (read-only or blocked), or null. */
        public readonly ?int $daysRemaining = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'level' => $this->level->value,
            'status' => $this->status,
            'trial_ends_at' => $this->trialEndsAt?->toIso8601String(),
            'current_period_end' => $this->currentPeriodEnd?->toIso8601String(),
            'grace_full_until' => $this->graceFullUntil?->toIso8601String(),
            'grace_readonly_until' => $this->graceReadonlyUntil?->toIso8601String(),
            'days_remaining' => $this->daysRemaining,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
