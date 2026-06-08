<?php

declare(strict_types=1);

namespace App\DataTransferObjects;

use App\Models\PricingTier;
use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;

/**
 * @implements Arrayable<string, mixed>
 */
final class PricingTierData implements Arrayable, JsonSerializable
{
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly ?string $description,
        public readonly string $rebatePercent,
        public readonly ?int $customersCount = null,
    ) {}

    public static function fromModel(PricingTier $tier): self
    {
        $count = $tier->getAttribute('customers_count');

        return new self(
            id: $tier->getKey(),
            name: $tier->name,
            description: $tier->description,
            rebatePercent: (string) $tier->rebate_percent,
            customersCount: $count !== null ? (int) $count : null,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $data = [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'rebate_percent' => $this->rebatePercent,
        ];

        if ($this->customersCount !== null) {
            $data['customers_count'] = $this->customersCount;
        }

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
