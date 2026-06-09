<?php

declare(strict_types=1);

namespace App\Actions\Suppliers;

use App\Models\Supplier;
use App\Models\SupplierOrder;
use Illuminate\Support\Facades\DB;

class CreateSupplierOrderAction
{
    /**
     * @param  array<string, mixed>  $attributes
     * @param  list<array<string, mixed>>  $items
     */
    public function execute(Supplier $supplier, array $attributes, array $items, string $createdById): SupplierOrder
    {
        return DB::transaction(function () use ($supplier, $attributes, $items, $createdById): SupplierOrder {
            $total = 0;
            $rows = [];
            foreach ($items as $item) {
                $quantity = (string) $item['quantity'];
                $unitPrice = (int) $item['unit_price'];
                $lineTotal = (int) round($unitPrice * (float) $quantity);
                $total += $lineTotal;

                $rows[] = [
                    'inventory_item_id' => $item['inventory_item_id'] ?? null,
                    'description' => (string) $item['description'],
                    'quantity' => $quantity,
                    'unit' => $item['unit'] ?? null,
                    'unit_price' => $unitPrice,
                    'total' => $lineTotal,
                ];
            }

            $order = SupplierOrder::create([
                'order_number' => $this->nextNumber(),
                'supplier_id' => $supplier->getKey(),
                'created_by_id' => $createdById,
                'notes' => $attributes['notes'] ?? null,
                'expected_at' => $attributes['expected_at'] ?? null,
                'total_amount' => $total,
            ]);

            foreach ($rows as $row) {
                $order->items()->create($row);
            }

            return $order;
        });
    }

    private function nextNumber(): string
    {
        $last = SupplierOrder::query()->orderByDesc('order_number')->value('order_number');
        $n = is_string($last) ? ((int) preg_replace('/\D/', '', $last)) + 1 : 1;

        return 'PO-'.str_pad((string) $n, 5, '0', STR_PAD_LEFT);
    }
}
