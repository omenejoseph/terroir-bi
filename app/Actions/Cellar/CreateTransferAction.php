<?php

declare(strict_types=1);

namespace App\Actions\Cellar;

use App\Enums\CellarTransferType;
use App\Models\CellarTransfer;
use App\Models\Vessel;
use App\Models\WineLot;
use App\Services\Cellar\LotVolumeService;
use App\Support\Quantity;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Record a wine movement. RACK moves volume between vessels within one lot
 * (total unchanged). BLEND/SPLIT move volume from one lot to another: the source
 * lot is drained and the destination lot grows, optionally into a named vessel.
 */
class CreateTransferAction
{
    public function __construct(private readonly LotVolumeService $volumes) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function execute(WineLot $fromLot, array $data, string $createdById): CellarTransfer
    {
        return DB::transaction(function () use ($fromLot, $data, $createdById): CellarTransfer {
            $type = CellarTransferType::from((string) $data['type']);
            $volume = Quantity::normalize((string) $data['volume_liters']);
            $toLot = WineLot::query()->whereKey((string) $data['to_lot_id'])->firstOrFail();
            $toVessel = ! empty($data['to_vessel_id'])
                ? Vessel::query()->whereKey((string) $data['to_vessel_id'])->firstOrFail()
                : null;

            if ($type === CellarTransferType::Rack) {
                if (empty($data['from_vessel_id']) || $toVessel === null) {
                    throw ValidationException::withMessages([
                        'from_vessel_id' => 'A rack needs both a source and a destination vessel.',
                    ]);
                }
                $this->volumes->moveWithinLot($fromLot, (string) $data['from_vessel_id'], $toVessel, $volume);
            } else {
                // Cross-lot: drain the source, grow the destination.
                $this->volumes->drain($fromLot, $volume);
                $toLot->current_volume = Quantity::add((string) $toLot->current_volume, $volume);
                $toLot->save();
                if ($toVessel !== null) {
                    $this->volumes->assignToVessel($toLot, $toVessel, $volume);
                }
            }

            /** @var CellarTransfer $transfer */
            $transfer = CellarTransfer::create([
                'from_lot_id' => $fromLot->getKey(),
                'to_lot_id' => $toLot->getKey(),
                'from_vessel_id' => $data['from_vessel_id'] ?? null,
                'to_vessel_id' => $data['to_vessel_id'] ?? null,
                'created_by_id' => $createdById,
                'type' => $type,
                'volume_liters' => $volume,
                'note' => $data['note'] ?? null,
            ]);

            return $transfer;
        });
    }
}
