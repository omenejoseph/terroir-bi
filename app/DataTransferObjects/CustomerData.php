<?php

declare(strict_types=1);

namespace App\DataTransferObjects;

use App\Models\Customer;
use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;

/**
 * @implements Arrayable<string, mixed>
 */
final class CustomerData implements Arrayable, JsonSerializable
{
    public function __construct(
        public readonly string $id,
        public readonly string $companyName,
        public readonly ?string $contactName,
        public readonly string $email,
        public readonly bool $isActive,
        public readonly string $rebatePercent,
        public readonly string $effectiveRebatePercent,
        public readonly bool $hidePrices,
        public readonly bool $hasOrderToken,
        public readonly ?Customer $model = null,
    ) {}

    public static function fromModel(Customer $customer): self
    {
        return new self(
            id: $customer->getKey(),
            companyName: $customer->company_name,
            contactName: $customer->contact_name,
            email: $customer->email,
            isActive: $customer->is_active,
            rebatePercent: (string) $customer->rebate_percent,
            effectiveRebatePercent: $customer->effectiveRebatePercent(),
            hidePrices: $customer->hide_prices,
            hasOrderToken: $customer->order_token !== null,
            model: $customer,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $c = $this->model;
        $tier = $c?->pricingTier;

        return [
            'id' => $this->id,
            'company_name' => $this->companyName,
            'contact_name' => $this->contactName,
            'email' => $this->email,
            'phone' => $c?->phone,
            'address' => $c?->address,
            'city' => $c?->city,
            'state' => $c?->state,
            'zip' => $c?->zip,
            'country' => $c?->country,
            'oib' => $c?->oib,
            'customer_type' => $c?->customer_type,
            'notes' => $c?->notes,
            'is_active' => $this->isActive,
            'rebate_percent' => $this->rebatePercent,
            'effective_rebate_percent' => $this->effectiveRebatePercent,
            'hide_prices' => $this->hidePrices,
            'is_agency' => $c?->is_agency,
            'allow_single_bottle' => $c?->allow_single_bottle,
            'exclude_from_stats' => $c?->exclude_from_stats,
            'reorder_contacted_at' => $c?->reorder_contacted_at?->toIso8601String(),
            'has_order_token' => $this->hasOrderToken,
            'pricing_tier' => $tier !== null
                ? ['id' => $tier->getKey(), 'name' => $tier->name, 'rebate_percent' => (string) $tier->rebate_percent]
                : null,
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
