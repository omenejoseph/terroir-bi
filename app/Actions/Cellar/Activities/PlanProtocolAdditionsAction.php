<?php

declare(strict_types=1);

namespace App\Actions\Cellar\Activities;

use App\Enums\CellarActivityType;
use App\Models\FermentationTemplate;
use App\Models\ProtocolAdditive;
use App\Models\WineLot;
use App\Services\Audit\AuditLogger;
use App\Services\Cellar\ProtocolDose;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

/**
 * Turn a lot's fermentation protocol into planned additions: one ADDITION per
 * additive per vessel holding the lot, dosed by the additive's basis —
 * per_L / per_hL on the vessel's litres, per_kg / per_100kg on the lot's
 * grape weight shared out by volume. Additives that need grape weight are
 * skipped (and reported) when the lot has none recorded.
 */
class PlanProtocolAdditionsAction
{
    public function __construct(
        private readonly CreateCellarActivitiesAction $create,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @return array{round_id: string|null, work_order_id: string|null, count: int, skipped_no_grape_weight: list<string>}
     */
    public function execute(WineLot $lot, string $plannedFor, string $userId): array
    {
        if ($lot->fermentation_template_id === null) {
            throw ValidationException::withMessages(['fermentation_template_id' => 'Assign a fermentation protocol first.']);
        }

        /** @var FermentationTemplate $template */
        $template = FermentationTemplate::query()->with('additives.enologicalProduct')->whereKey($lot->fermentation_template_id)->firstOrFail();

        if ($template->additives->isEmpty()) {
            throw ValidationException::withMessages(['additives' => 'This protocol has no additive doses — add them to the protocol first.']);
        }

        $scopes = $lot->vesselLots()->get()
            ->map(fn ($vl): array => ['vessel_id' => $vl->vessel_id, 'liters' => (float) $vl->volume])
            ->filter(fn (array $s): bool => $s['liters'] > 0)
            ->values()
            ->all();

        if ($scopes === []) {
            throw ValidationException::withMessages(['vessel_id' => 'This lot is not in any vessel — assign it to a vessel first.']);
        }

        $totalLiters = array_sum(array_column($scopes, 'liters'));
        $grapeKg = (float) ($lot->harvest_weight_kg ?? 0);
        $skipped = [];
        $activities = [];

        foreach ($template->additives as $additive) {
            /** @var ProtocolAdditive $additive */
            if ($additive->basis->needsGrapeWeight() && $grapeKg <= 0) {
                $skipped[] = $additive->name;

                continue;
            }

            foreach ($scopes as $scope) {
                $qty = (float) ProtocolDose::quantity($additive->basis, (float) $additive->dose, $scope['liters'], $grapeKg * $scope['liters'] / $totalLiters);
                if ($qty <= 0) {
                    continue;
                }

                $product = $additive->enologicalProduct;
                $activities[] = [
                    'type' => CellarActivityType::Addition->value,
                    'planned_for' => $plannedFor,
                    'wine_lot_id' => $lot->getKey(),
                    'vessel_id' => $scope['vessel_id'],
                    'payload' => [
                        'enological_product_id' => $additive->enological_product_id,
                        'product_name' => $product !== null ? $product->name : $additive->name,
                        'category' => 'Protocol',
                        'unit' => $product !== null ? $product->unit : 'g',
                        'quantity' => $qty,
                        'cost_per_unit' => $product?->cost_per_unit?->minorFloat(),
                        'target_note' => implode(' · ', array_filter(["Protocol {$template->name}", $additive->stage])),
                    ],
                ];
            }
        }

        if ($activities === []) {
            throw ValidationException::withMessages(['additives' => 'Nothing to plan — check the vessel volumes and the protocol doses.']);
        }

        $result = $this->create->execute([
            'round_name' => trim("Protocol {$template->name} — {$lot->name} ".($lot->vintage ?? '')),
            'activities' => $activities,
        ], $userId);

        $this->audit->record(Auth::user(), 'wine_lot.protocol_additions_planned', $lot, [
            'round_id' => $result['round_id'],
            'count' => $result['count'],
            'skipped_no_grape_weight' => array_values(array_unique($skipped)),
        ]);

        return [...$result, 'skipped_no_grape_weight' => array_values(array_unique($skipped))];
    }
}
