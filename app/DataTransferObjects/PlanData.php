<?php

declare(strict_types=1);

namespace App\DataTransferObjects;

use App\Models\Plan;
use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;

/**
 * A subscription plan as exposed to the back office / billing API.
 *
 * @implements Arrayable<string, mixed>
 */
final class PlanData implements Arrayable, JsonSerializable
{
    public function __construct(public readonly Plan $plan) {}

    public static function fromModel(Plan $plan): self
    {
        return new self($plan);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $plan = $this->plan;

        return [
            'id' => $plan->getKey(),
            'name' => $plan->name,
            'slug' => $plan->slug,
            'price' => $plan->price_minor?->jsonSerialize(),
            'currency' => $plan->currency,
            'modules' => $plan->moduleKeys(),
            'stripe_price_id' => $plan->stripe_price_id,
            'trial_days' => $plan->trial_days,
            'grace_full_days' => $plan->grace_full_days,
            'grace_readonly_days' => $plan->grace_readonly_days,
            'interval' => $plan->interval,
            'is_active' => $plan->is_active,
            'is_public' => $plan->is_public,
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
