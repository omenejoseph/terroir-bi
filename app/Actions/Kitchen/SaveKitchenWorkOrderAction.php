<?php

declare(strict_types=1);

namespace App\Actions\Kitchen;

use App\Enums\KitchenWorkOrderStatus;
use App\Models\KitchenWorkOrder;
use App\Support\Quantity;
use Illuminate\Support\Facades\DB;

class SaveKitchenWorkOrderAction
{
    /**
     * Add or edit a prep ticket. Its lines are replaced as a whole, inside one transaction (the old
     * app deleted and re-created them without one).
     *
     * @param  array{title: string, date: string, assigned_to?: ?string, notes?: ?string}  $data
     * @param  list<array{item_id?: ?string, custom_name?: ?string, quantity: string|int|float, unit?: ?string, notes?: ?string}>  $lines
     */
    public function execute(array $data, array $lines, string $userId, ?KitchenWorkOrder $order = null): KitchenWorkOrder
    {
        return DB::transaction(function () use ($data, $lines, $userId, $order): KitchenWorkOrder {
            if ($order === null) {
                $order = KitchenWorkOrder::create($data + ['user_id' => $userId, 'status' => KitchenWorkOrderStatus::Planned]);
            } else {
                $order->update($data);
                $order->items()->delete();
            }

            foreach ($lines as $position => $line) {
                $order->items()->create([
                    'item_id' => $line['item_id'] ?? null,
                    'custom_name' => ($line['item_id'] ?? null) === null ? ($line['custom_name'] ?? null) : null,
                    'quantity' => Quantity::normalize((string) $line['quantity']),
                    'unit' => $line['unit'] ?? null,
                    'notes' => $line['notes'] ?? null,
                    'sort_order' => $position,
                ]);
            }

            return $order;
        });
    }
}
