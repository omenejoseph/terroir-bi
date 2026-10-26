<?php

declare(strict_types=1);

namespace App\Actions\Kitchen;

use App\Enums\KitchenMovementType;
use App\Models\KitchenItem;
use App\Services\Kitchen\KitchenStockLedger;
use App\Support\Quantity;
use Illuminate\Support\Facades\DB;

class SaveKitchenItemAction
{
    public function __construct(private readonly KitchenStockLedger $ledger) {}

    /**
     * Add or edit an item. A new item's opening stock is booked as an IN movement so its history
     * adds up to its stock (the old app set the figure without one). Editing never changes stock —
     * that goes through an adjustment.
     *
     * @param  array<string, mixed>  $data
     */
    public function execute(array $data, string $userId, ?KitchenItem $item = null): KitchenItem
    {
        return DB::transaction(function () use ($data, $userId, $item): KitchenItem {
            $opening = isset($data['opening_stock']) ? Quantity::normalize((string) $data['opening_stock']) : '0.000';
            unset($data['opening_stock'], $data['current_stock']);

            if ($item !== null) {
                $item->update($data);

                return $item;
            }

            $item = KitchenItem::create($data + [
                'sort_order' => (int) KitchenItem::query()->max('sort_order') + 1,
            ]);

            if (Quantity::compare($opening, '0') !== 0) {
                $this->ledger->move($item, KitchenMovementType::In, $opening, __('Opening stock'), $userId);
            }

            return $item;
        });
    }
}
