<?php

declare(strict_types=1);

namespace App\Actions\Cellar\Activities;

use App\Actions\Cellar\AddCellarAdditionAction;
use App\Actions\Cellar\AddCellarAnalysisAction;
use App\Actions\Cellar\AddCellarProcessAction;
use App\Actions\Cellar\CreateTransferAction;
use App\Enums\CellarActivityStatus;
use App\Enums\CellarActivityType;
use App\Enums\CellarTransferType;
use App\Models\CellarActivity;
use App\Models\CellarAnalysis;
use App\Models\CellarTransfer;
use App\Models\Vessel;
use App\Models\VesselLot;
use App\Models\WineLot;
use App\Services\Audit\AuditLogger;
use App\Services\Cellar\LotVolumeService;
use App\Services\Cellar\RoundWorkOrderSync;
use App\Support\Quantity;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Execute a planned activity — the cellar team's "✓ Done" on Cellar › Today.
 * Each type runs through the same Action a manual entry would (so the stock
 * rule, capacity checks and volume sync all apply), then the activity is
 * stamped DONE with what was actually applied. `deviated` marks a quantity or
 * litre amount that differed from the plan.
 *
 * The row is locked and must still be PLANNED, so a double tap or two people
 * completing the same item cannot apply it twice (the prototype could), and a
 * skipped item cannot be completed.
 */
class CompleteCellarActivityAction
{
    public function __construct(
        private readonly AddCellarAdditionAction $addAddition,
        private readonly AddCellarAnalysisAction $addAnalysis,
        private readonly AddCellarProcessAction $addProcess,
        private readonly CreateTransferAction $createTransfer,
        private readonly LotVolumeService $volumes,
        private readonly RoundWorkOrderSync $rounds,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array{quantity?: float|int|string|null, liters?: float|int|string|null, values?: array<string, mixed>|null, note?: string|null}  $overrides
     */
    public function execute(CellarActivity $activity, array $overrides, string $userId): CellarActivity
    {
        $activity = DB::transaction(function () use ($activity, $overrides, $userId): CellarActivity {
            /** @var CellarActivity $activity */
            $activity = CellarActivity::query()->whereKey($activity->getKey())->lockForUpdate()->firstOrFail();

            if (! $activity->isPlanned()) {
                throw ValidationException::withMessages([
                    'status' => $activity->status === CellarActivityStatus::Done
                        ? 'This work has already been done.'
                        : 'This work was skipped — plan it again to do it.',
                ]);
            }

            $note = isset($overrides['note']) && $overrides['note'] !== '' ? (string) $overrides['note'] : null;
            [$applied, $deviated] = $this->apply($activity, $overrides, $note, $userId);

            $activity->status = CellarActivityStatus::Done;
            $activity->deviated = $deviated;
            $activity->applied_at = now();
            $activity->executed_by_id = $userId;
            $activity->payload = [...$activity->payload, 'applied' => $applied];
            if ($note !== null) {
                $activity->note = implode(' · ', array_filter([$activity->note, $note]));
            }
            $activity->save();

            $this->rounds->sync($activity->round_id);

            $this->audit->record(Auth::user(), 'cellar_activity.completed', $activity, [
                'type' => $activity->type->value,
                'wine_lot_id' => $activity->wine_lot_id,
                'vessel_id' => $activity->vessel_id,
                'applied' => $applied,
                'deviated' => $deviated,
            ]);

            return $activity;
        });

        return $activity;
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array{0: array<string, mixed>, 1: bool}
     */
    private function apply(CellarActivity $activity, array $overrides, ?string $note, string $userId): array
    {
        $p = $activity->payload;

        return match ($activity->type) {
            CellarActivityType::Addition => $this->addition($activity, $p, $overrides, $note, $userId),
            CellarActivityType::Analysis => $this->analysis($activity, $overrides, $note, $userId),
            CellarActivityType::Transfer => $this->transfer($activity, $p, $overrides, $note, $userId),
            CellarActivityType::Process => $this->process($activity, $p, $note, $userId),
            CellarActivityType::Topping => $this->topping($activity, $p, $overrides, $note, $userId),
            CellarActivityType::Vineyard => [['kind' => $p['kind'] ?? null, 'parcel_name' => $p['parcel_name'] ?? null], false],
        };
    }

    /**
     * @param  array<string, mixed>  $p
     * @param  array<string, mixed>  $overrides
     * @return array{0: array<string, mixed>, 1: bool}
     */
    private function addition(CellarActivity $activity, array $p, array $overrides, ?string $note, string $userId): array
    {
        [$qty, $deviated] = $this->amount($p['quantity'], $overrides['quantity'] ?? null, 'quantity', 'Enter the quantity added.');

        $this->addAddition->execute($this->lot($activity), [
            'name' => $p['product_name'],
            'category' => $p['category'] ?? 'Planned',
            'unit' => $p['unit'],
            'quantity' => $qty,
            'cost_per_unit' => $p['cost_per_unit'] ?? null,
            'enological_product_id' => $p['enological_product_id'] ?? null,
            'vessel_id' => $activity->vessel_id,
            'note' => $this->join($p['target_note'] ?? null, $note),
        ], $userId);

        return [['quantity' => $qty], $deviated];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array{0: array<string, mixed>, 1: bool}
     */
    private function analysis(CellarActivity $activity, array $overrides, ?string $note, string $userId): array
    {
        $values = [];
        foreach ((array) ($overrides['values'] ?? []) as $param => $value) {
            if (in_array($param, CellarAnalysis::PARAMETERS, true) && is_numeric($value)) {
                $values[$param] = $value;
            }
        }

        if ($values === []) {
            throw ValidationException::withMessages(['values' => 'Enter at least one reading.']);
        }

        $this->addAnalysis->execute($this->lot($activity), [
            ...$values,
            'vessel_id' => $activity->vessel_id,
            'note' => $note,
        ], $userId);

        return [['values' => $values], false];
    }

    /**
     * @param  array<string, mixed>  $p
     * @param  array<string, mixed>  $overrides
     * @return array{0: array<string, mixed>, 1: bool}
     */
    private function transfer(CellarActivity $activity, array $p, array $overrides, ?string $note, string $userId): array
    {
        [$liters, $deviated] = $this->amount($p['liters'], $overrides['liters'] ?? null, 'liters', 'Enter the litres moved.');
        $lot = $this->lot($activity);

        $this->createTransfer->execute($lot, [
            'type' => CellarTransferType::Rack->value,
            'volume_liters' => $liters,
            'to_lot_id' => $lot->getKey(),
            'from_vessel_id' => $p['from_vessel_id'],
            'to_vessel_id' => $p['to_vessel_id'],
            'note' => $this->join('Planned activity', $note),
        ], $userId);

        return [['liters' => $liters], $deviated];
    }

    /**
     * @param  array<string, mixed>  $p
     * @return array{0: array<string, mixed>, 1: bool}
     */
    private function process(CellarActivity $activity, array $p, ?string $note, string $userId): array
    {
        $this->addProcess->execute($this->lot($activity), [
            'kind' => $p['kind'],
            'vessel_id' => $activity->vessel_id,
            'note' => $this->join($p['note'] ?? null, $note),
        ], $userId);

        return [['kind' => $p['kind']], false];
    }

    /**
     * Topping from a named source vessel moves real wine (a rack when the
     * source holds the same lot, otherwise a blend transfer drawn from that
     * vessel). Without a source the litres are added and a "Topping" process
     * records who added how much — the prototype added wine from nowhere and
     * left no trace.
     *
     * @param  array<string, mixed>  $p
     * @param  array<string, mixed>  $overrides
     * @return array{0: array<string, mixed>, 1: bool}
     */
    private function topping(CellarActivity $activity, array $p, array $overrides, ?string $note, string $userId): array
    {
        [$liters, $deviated] = $this->amount($p['liters'], $overrides['liters'] ?? null, 'liters', 'Enter the litres topped up.');
        $lot = $this->lot($activity);
        /** @var Vessel $target */
        $target = Vessel::query()->whereKey((string) $activity->vessel_id)->firstOrFail();
        $sourceVesselId = $p['source_vessel_id'] ?? null;
        $reason = $this->join($p['reason'] ?? null, $note);

        if ($sourceVesselId === null) {
            $this->volumes->adjust($lot, $liters, $target->getKey());
            $this->addProcess->execute($lot, [
                'kind' => 'Topping',
                'vessel_id' => $target->getKey(),
                'volume' => $liters,
                'note' => $reason ?? 'Topped up — no source vessel recorded.',
            ], $userId);

            return [['liters' => $liters, 'source_vessel_id' => null], $deviated];
        }

        $sources = VesselLot::query()->where('vessel_id', (string) $sourceVesselId)->get();
        if ($sources->isEmpty()) {
            throw ValidationException::withMessages(['source_vessel_id' => 'The source vessel is empty.']);
        }

        $sameLot = $sources->firstWhere('wine_lot_id', $lot->getKey());
        if ($sameLot !== null) {
            $this->createTransfer->execute($lot, [
                'type' => CellarTransferType::Rack->value,
                'volume_liters' => $liters,
                'to_lot_id' => $lot->getKey(),
                'from_vessel_id' => $sourceVesselId,
                'to_vessel_id' => $target->getKey(),
                'note' => $this->join('Topping', $reason),
            ], $userId);

            return [['liters' => $liters, 'source_vessel_id' => $sourceVesselId], $deviated];
        }

        if ($sources->count() > 1) {
            throw ValidationException::withMessages(['source_vessel_id' => 'The source vessel holds several lots — rack it first or choose another source.']);
        }

        /** @var VesselLot $source */
        $source = $sources->first();
        if (Quantity::compare((string) $source->volume, $liters) < 0) {
            throw ValidationException::withMessages(['liters' => "The source vessel only holds {$source->volume} L."]);
        }

        /** @var WineLot $sourceLot */
        $sourceLot = WineLot::query()->whereKey($source->wine_lot_id)->firstOrFail();

        // Draw from that exact vessel (not FIFO across the source lot), then grow the target.
        $this->volumes->adjust($sourceLot, Quantity::negate($liters), (string) $sourceVesselId);
        $lot->refresh();
        $lot->current_volume = Quantity::add((string) $lot->current_volume, $liters);
        $lot->save();
        $this->volumes->assignToVessel($lot, $target, $liters);

        CellarTransfer::create([
            'from_lot_id' => $sourceLot->getKey(),
            'to_lot_id' => $lot->getKey(),
            'from_vessel_id' => $sourceVesselId,
            'to_vessel_id' => $target->getKey(),
            'created_by_id' => $userId,
            'type' => CellarTransferType::Blend,
            'volume_liters' => $liters,
            'note' => $this->join('Topping', $reason),
        ]);

        return [['liters' => $liters, 'source_vessel_id' => $sourceVesselId, 'source_lot_id' => $sourceLot->getKey()], $deviated];
    }

    /**
     * The amount to apply (override or planned) and whether it deviates.
     *
     * @return array{0: string, 1: bool}
     */
    private function amount(mixed $planned, mixed $override, string $field, string $message): array
    {
        $plannedQty = Quantity::normalize((string) $planned);

        if ($override === null || $override === '') {
            return [$plannedQty, false];
        }

        if (! is_numeric($override) || (float) $override <= 0) {
            throw ValidationException::withMessages([$field => $message]);
        }

        $actual = Quantity::normalize((string) $override);

        return [$actual, Quantity::compare($actual, $plannedQty) !== 0];
    }

    private function lot(CellarActivity $activity): WineLot
    {
        if ($activity->wine_lot_id === null) {
            throw ValidationException::withMessages(['wine_lot_id' => 'This work has no wine lot.']);
        }

        /** @var WineLot $lot */
        $lot = WineLot::query()->whereKey($activity->wine_lot_id)->firstOrFail();

        return $lot;
    }

    private function join(?string ...$parts): ?string
    {
        $parts = array_filter($parts, fn (?string $s): bool => $s !== null && $s !== '');

        return $parts === [] ? null : implode(' · ', $parts);
    }
}
