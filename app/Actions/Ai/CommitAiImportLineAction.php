<?php

declare(strict_types=1);

namespace App\Actions\Ai;

use App\Actions\Costs\CreateCostAction;
use App\Actions\Customers\CreateCustomerAction;
use App\Actions\Finance\CreateInflowAction;
use App\Actions\Inventory\CreateInventoryItemAction;
use App\Actions\Orders\CreateOrderAction;
use App\Actions\Suppliers\CreateSupplierAction;
use App\Enums\AiImportLineStatus;
use App\Enums\AiTargetType;
use App\Enums\InventoryCategory;
use App\Models\AiImportLine;
use App\Models\Customer;
use App\Models\InventoryItem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Commits a single reviewed line into a real, AI-tagged record by routing its
 * (possibly edited) payload to the matching domain create-action. Idempotent:
 * an already-committed line is returned unchanged.
 */
class CommitAiImportLineAction
{
    public function __construct(
        private readonly CreateCostAction $costs,
        private readonly CreateInflowAction $inflows,
        private readonly CreateSupplierAction $suppliers,
        private readonly CreateInventoryItemAction $inventory,
        private readonly CreateOrderAction $orders,
        private readonly CreateCustomerAction $customers,
    ) {}

    public function execute(AiImportLine $line, string $userId): AiImportLine
    {
        if ($line->committed_id !== null) {
            return $line;
        }

        if (! $line->status->isCommittable()) {
            throw new RuntimeException('Only approved or edited lines can be committed.');
        }

        $payload = $line->effectivePayload();
        $meta = [
            'ai_import_id' => $line->ai_import_id,
            'ai_import_line_id' => $line->getKey(),
            'confidence' => $line->confidence,
            'model' => $line->import?->model,
        ];

        $committedId = DB::transaction(fn (): string => match ($line->target_type) {
            AiTargetType::Cost => $this->commitCost($payload, $meta, $userId),
            AiTargetType::Inflow => $this->commitInflow($payload, $meta, $userId),
            AiTargetType::Supplier => $this->commitSupplier($payload, $meta),
            AiTargetType::InventoryItem => $this->commitInventory($payload, $meta),
            AiTargetType::Order => $this->commitOrder($payload, $meta, $userId),
        });

        $line->update([
            'status' => AiImportLineStatus::Committed,
            'committed_id' => $committedId,
        ]);

        return $line;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $meta
     */
    private function commitCost(array $payload, array $meta, string $userId): string
    {
        $attributes = array_merge($payload, [
            'category' => $payload['category'] ?? 'uncategorised',
            'is_ai_generated' => true,
            'ai_metadata' => $meta,
        ]);

        return $this->costs->execute($attributes, [], $userId)->getKey();
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $meta
     */
    private function commitInflow(array $payload, array $meta, string $userId): string
    {
        $attributes = array_merge($payload, [
            'is_ai_generated' => true,
            'ai_metadata' => $meta,
        ]);

        return $this->inflows->execute($attributes, $userId)->getKey();
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $meta
     */
    private function commitSupplier(array $payload, array $meta): string
    {
        $attributes = array_merge($payload, [
            'is_ai_generated' => true,
            'ai_metadata' => $meta,
        ]);

        return $this->suppliers->execute($attributes)->supplier->getKey();
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $meta
     */
    private function commitInventory(array $payload, array $meta): string
    {
        $category = InventoryCategory::tryFrom(strtoupper((string) ($payload['category'] ?? '')))
            ?? InventoryCategory::Finished;

        $attributes = [
            'name' => $payload['name'] ?? 'Untitled item',
            'sku' => $payload['sku'] ?? $this->generateSku((string) ($payload['name'] ?? 'item')),
            'category' => $category->value,
            'unit' => $payload['unit'] ?? 'pcs',
            'is_active' => true,
            'is_ai_generated' => true,
            'ai_metadata' => $meta,
        ];

        foreach (['default_price', 'cost_per_unit'] as $money) {
            if (isset($payload[$money])) {
                $attributes[$money] = (int) $payload[$money];
            }
        }
        if (isset($payload['current_stock'])) {
            $attributes['current_stock'] = (string) $payload['current_stock'];
        }

        return $this->inventory->execute($attributes)->id;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $meta
     */
    private function commitOrder(array $payload, array $meta, string $userId): string
    {
        $customer = $this->resolveCustomer($payload);

        $items = array_map(fn (array $item): array => [
            'custom_description' => $item['description'] ?? 'Item',
            'inventory_item_id' => $this->resolveInventoryId($item['sku'] ?? null),
            'quantity' => max(1, (int) ($item['quantity'] ?? 1)),
            'unit_price' => (int) ($item['unit_price'] ?? 0),
        ], $payload['items'] ?? []);

        $order = $this->orders->execute($customer, $userId, [
            'status' => $payload['status'] ?? 'RECEIVED',
            'notes' => $payload['notes'] ?? null,
            'deduct_stock' => false, // never move stock from an imported document
            'items' => $items,
        ]);

        $order->forceFill(['is_ai_generated' => true, 'ai_metadata' => $meta])->save();

        return $order->getKey();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function resolveCustomer(array $payload): Customer
    {
        if (! empty($payload['customer_id'])) {
            $found = Customer::query()->find((string) $payload['customer_id']);
            if ($found !== null) {
                return $found;
            }
        }

        $name = trim((string) ($payload['customer_name'] ?? 'Unknown customer')) ?: 'Unknown customer';

        $existing = Customer::query()->whereRaw('LOWER(company_name) = ?', [mb_strtolower($name)])->first();
        if ($existing !== null) {
            return $existing;
        }

        $data = $this->customers->execute([
            'company_name' => $name,
            'email' => $this->placeholderEmail($name),
        ]);

        return Customer::query()->findOrFail($data->id);
    }

    private function resolveInventoryId(?string $sku): ?string
    {
        if ($sku === null || $sku === '') {
            return null;
        }

        return InventoryItem::query()->where('sku', $sku)->value('id');
    }

    private function generateSku(string $name): string
    {
        return strtoupper(Str::slug(Str::limit($name, 12, ''), '-') ?: 'ITEM').'-'.strtoupper(Str::random(4));
    }

    private function placeholderEmail(string $name): string
    {
        return 'ai-'.(Str::slug($name) ?: 'customer').'-'.strtolower(Str::random(5)).'@import.local';
    }
}
