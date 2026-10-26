<?php

declare(strict_types=1);

namespace App\Actions\Kitchen;

use App\Enums\KitchenCheckStatus;
use App\Models\KitchenCheckItem;
use App\Support\Quantity;
use Illuminate\Validation\ValidationException;

class RecordKitchenCountAction
{
    /** Enter (or clear) what was counted; the difference is counted − system stock. */
    public function execute(KitchenCheckItem $line, ?string $count): KitchenCheckItem
    {
        if ($line->check?->status !== KitchenCheckStatus::InProgress) {
            throw ValidationException::withMessages(['physical_count' => __('This stock-take is already completed.')]);
        }

        $count = $count === null || trim($count) === '' ? null : Quantity::normalize($count);

        $line->forceFill([
            'physical_count' => $count,
            'difference' => $count === null ? null : Quantity::sub($count, (string) $line->system_stock),
        ])->save();

        return $line;
    }
}
