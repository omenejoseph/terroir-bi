<?php

declare(strict_types=1);

namespace App\Actions\Kitchen;

use App\Enums\KitchenCheckStatus;
use App\Enums\KitchenMovementType;
use App\Models\KitchenCheck;
use App\Models\KitchenCheckItem;
use App\Models\KitchenItem;
use App\Services\Kitchen\KitchenStockLedger;
use App\Support\Quantity;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CompleteKitchenCheckAction
{
    public function __construct(private readonly KitchenStockLedger $ledger) {}

    /**
     * Apply the count: every counted line whose figure differs from the snapshot becomes an
     * ADJUSTMENT to the counted figure, and uncounted lines are left alone.
     *
     * Like the old app, the counted figure replaces the stock, so anything booked between the start
     * of the count and now is overwritten — a count is the truth at the moment it is taken.
     */
    public function execute(KitchenCheck $check, string $userId): KitchenCheck
    {
        if ($check->status !== KitchenCheckStatus::InProgress) {
            throw ValidationException::withMessages(['check' => __('This stock-take is already completed.')]);
        }

        return DB::transaction(function () use ($check, $userId): KitchenCheck {
            $check->items()->with('item')->get()->each(function (KitchenCheckItem $line) use ($check, $userId): void {
                if ($line->physical_count === null || ! $line->item instanceof KitchenItem) {
                    return;
                }

                if (Quantity::compare((string) $line->physical_count, (string) $line->system_stock) === 0) {
                    return;
                }

                $this->ledger->move(
                    $line->item,
                    KitchenMovementType::Adjustment,
                    (string) $line->physical_count,
                    __('Stock-take :date', ['date' => $check->date->toDateString()]),
                    $userId,
                );
            });

            $check->forceFill(['status' => KitchenCheckStatus::Completed, 'completed_at' => now()])->save();

            return $check;
        });
    }
}
