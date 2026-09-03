<?php

declare(strict_types=1);

namespace App\DataTransferObjects;

use App\Models\Customer;
use App\Models\InventoryItem;
use App\Models\Order;
use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;

/**
 * One row of the header's global search (Figma 389:1679): the three models it
 * searches — Order, Customer, InventoryItem — projected onto one shape so the
 * dropdown never needs to know which kind of record it is rendering.
 *
 * @implements Arrayable<string, mixed>
 */
final class SearchResultData implements Arrayable, JsonSerializable
{
    public function __construct(
        public readonly string $id,
        public readonly string $title,
        public readonly string $subtitle,
        public readonly string $url,
    ) {}

    public static function fromOrder(Order $order): self
    {
        return new self(
            id: $order->getKey(),
            title: $order->order_number,
            subtitle: $order->customer?->company_name ?? '',
            // No dedicated show route — the list reads ?order= and opens the
            // same drawer this URL lands on directly.
            url: '/orders?order='.$order->getKey(),
        );
    }

    public static function fromCustomer(Customer $customer): self
    {
        return new self(
            id: $customer->getKey(),
            title: $customer->company_name,
            subtitle: $customer->contact_name ?? $customer->email ?? '',
            url: '/customers/'.$customer->getKey(),
        );
    }

    public static function fromInventoryItem(InventoryItem $item): self
    {
        return new self(
            id: $item->getKey(),
            title: $item->name,
            subtitle: $item->sku,
            url: '/inventory/'.$item->getKey(),
        );
    }

    /**
     * @return array{id: string, title: string, subtitle: string, url: string}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'subtitle' => $this->subtitle,
            'url' => $this->url,
        ];
    }

    /**
     * @return array{id: string, title: string, subtitle: string, url: string}
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
