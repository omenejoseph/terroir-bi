<?php

declare(strict_types=1);

namespace App\DataTransferObjects;

use App\Models\InventoryItem;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderNote;
use App\Models\OrderStatusHistory;
use App\Models\User;
use App\Support\Money\Money;
use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;

/**
 * Full order representation for the API. COGS (cost_per_unit) is only included
 * when the caller may see financials.
 *
 * @implements Arrayable<string, mixed>
 */
final class OrderData implements Arrayable, JsonSerializable
{
    /**
     * @param  array<string, mixed>|null  $payment
     * @param  array<string, string|null>  $imageUrls  Lead-image URL per order-item id (presigned by the caller).
     */
    public function __construct(
        public readonly Order $order,
        public readonly bool $showFinancials,
        public readonly ?array $payment = null,
        public readonly array $imageUrls = [],
    ) {}

    /**
     * @param  array<string, mixed>|null  $payment
     * @param  array<string, string|null>  $imageUrls
     */
    public static function fromModel(Order $order, bool $showFinancials, ?array $payment = null, array $imageUrls = []): self
    {
        return new self($order, $showFinancials, $payment, $imageUrls);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $order = $this->order;
        $customer = $order->customer;

        return [
            'id' => $order->getKey(),
            'order_number' => $order->order_number,
            'status' => $order->status->value,
            'total_amount' => $order->total_amount->jsonSerialize(),
            'notes' => $order->notes,
            'customer' => $customer !== null
                ? ['id' => $customer->getKey(), 'company_name' => $customer->company_name]
                : null,
            'created_by' => $this->user($order->createdBy),
            'is_backorder' => $order->is_backorder,
            'backorder_date' => $order->backorder_date?->toIso8601String(),
            'deduct_stock' => $order->deduct_stock,
            'shipping_cost' => $order->shipping_cost?->jsonSerialize(),
            'shipping_paid_by_us' => $order->shipping_paid_by_us,
            'is_consignment' => $order->is_consignment,
            'consignment_closed_at' => $order->consignment_closed_at?->toIso8601String(),
            'payment' => $this->payment,
            'profitability' => $this->profitability(),
            'created_at' => $order->created_at?->toIso8601String(),
            'items' => $order->items->map(fn (OrderItem $item) => $this->item($item))->all(),
            'status_history' => $order->statusHistories
                ->sortBy('created_at')
                ->values()
                ->map(fn (OrderStatusHistory $h) => [
                    'status' => $h->status->value,
                    'note' => $h->note,
                    'changed_by' => $this->user($h->changedBy),
                    'created_at' => $h->created_at?->toIso8601String(),
                ])->all(),
            'comments' => $order->orderNotes
                ->sortBy('created_at')
                ->values()
                ->map(fn (OrderNote $n) => [
                    'id' => $n->getKey(),
                    'content' => $n->content,
                    'author' => $this->user($n->author),
                    'created_at' => $n->created_at?->toIso8601String(),
                ])->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function item(OrderItem $item): array
    {
        $product = $item->inventoryItem;
        $hasProduct = $product instanceof InventoryItem;

        $row = [
            'id' => $item->getKey(),
            'inventory_item_id' => $item->inventory_item_id,
            'name' => $this->itemName($item),
            'sku' => $hasProduct ? $product->sku : null,
            'group' => $hasProduct ? $product->group : null,
            'unit_size' => $hasProduct ? $product->unit_size : null,
            'bottles_per_case' => $hasProduct ? $product->bottles_per_case : null,
            'image_url' => $this->imageUrls[$item->getKey()] ?? null,
            'quantity' => $item->quantity,
            'unit_type' => $item->unit_type,
            'unit_price' => $item->unit_price->jsonSerialize(),
            'total' => $item->total->jsonSerialize(),
            'custom_description' => $item->custom_description,
        ];

        if ($this->showFinancials) {
            $row['cost_per_unit'] = $item->cost_per_unit?->jsonSerialize();
            // Line profit = revenue (total) − cost (cost_per_unit × qty). Null when cost is unknown.
            $row['profit'] = $item->cost_per_unit !== null
                ? Money::fromMinor(
                    $item->total->getMinorAmount() - $item->cost_per_unit->getMinorAmount() * $item->quantity,
                    $item->total->getCurrencyCode(),
                )->jsonSerialize()
                : null;
        }

        return $row;
    }

    private function itemName(OrderItem $item): string
    {
        $product = $item->inventoryItem;

        return $product instanceof InventoryItem
            ? $product->name
            : (string) ($item->custom_description ?? '');
    }

    /**
     * Order-level profitability for the detail view. Mirrors the period analytics
     * COGS convention (cost_per_unit is the per-order-unit snapshot, multiplied by
     * quantity). Lines without a recorded cost are listed so the operator can fill
     * them in; freight is only deducted when we pay it. Null unless the caller may
     * see financials, and skipped for consignment orders — those report realized
     * profit through the consignment summary instead.
     *
     * @return array<string, mixed>|null
     */
    private function profitability(): ?array
    {
        if (! $this->showFinancials || $this->order->is_consignment) {
            return null;
        }

        $currency = $this->order->total_amount->getCurrencyCode();
        $revenue = 0;
        $cogs = 0;
        $missing = [];

        foreach ($this->order->items as $item) {
            // Gratis lines (priced at zero) are excluded from order profitability:
            // the giveaway is a marketing cost to the winery, not part of this
            // order's product margin. Mirrors the items table footer.
            if ($item->unit_price->getMinorAmount() === 0) {
                continue;
            }

            $revenue += $item->total->getMinorAmount();

            if ($item->cost_per_unit === null) {
                $missing[] = $this->itemName($item);

                continue;
            }

            $cogs += $item->cost_per_unit->getMinorAmount() * $item->quantity;
        }

        // Freight is a real cost only when we pay it; otherwise it's informational.
        $logistics = $this->order->shipping_paid_by_us && $this->order->shipping_cost !== null
            ? $this->order->shipping_cost->getMinorAmount()
            : 0;

        $profit = $revenue - $cogs - $logistics;

        return [
            'revenue' => Money::fromMinor($revenue, $currency)->jsonSerialize(),
            'cogs' => Money::fromMinor($cogs, $currency)->jsonSerialize(),
            'logistics' => $logistics > 0 ? Money::fromMinor($logistics, $currency)->jsonSerialize() : null,
            'gross_profit' => Money::fromMinor($profit, $currency)->jsonSerialize(),
            'margin_percent' => $revenue > 0 ? number_format($profit / $revenue * 100, 2, '.', '') : '0.00',
            'complete' => $missing === [],
            'missing_cost_items' => $missing,
        ];
    }

    /**
     * @return array{id: string, name: string}|null
     */
    private function user(?User $user): ?array
    {
        if ($user === null) {
            return null;
        }

        $name = trim($user->first_name.' '.$user->last_name);

        return ['id' => $user->getKey(), 'name' => $name];
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
