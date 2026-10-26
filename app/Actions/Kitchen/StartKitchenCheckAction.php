<?php

declare(strict_types=1);

namespace App\Actions\Kitchen;

use App\Enums\KitchenCheckStatus;
use App\Enums\KitchenLocation;
use App\Models\KitchenCheck;
use App\Models\KitchenItem;
use Illuminate\Support\Facades\DB;

class StartKitchenCheckAction
{
    /** Snapshot every active item (in one location, or all) as the system stock to count against. */
    public function execute(string $date, ?KitchenLocation $location, ?string $note, string $userId): KitchenCheck
    {
        return DB::transaction(function () use ($date, $location, $note, $userId): KitchenCheck {
            $check = KitchenCheck::create([
                'date' => $date,
                'status' => KitchenCheckStatus::InProgress,
                'location' => $location,
                'note' => $note,
                'user_id' => $userId,
            ]);

            KitchenItem::query()
                ->where('is_active', true)
                ->when($location !== null, fn ($q) => $q->where('location', $location))
                ->get()
                ->each(fn (KitchenItem $item) => $check->items()->create([
                    'item_id' => $item->getKey(),
                    'system_stock' => $item->current_stock,
                ]));

            return $check;
        });
    }
}
