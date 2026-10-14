<?php

declare(strict_types=1);

namespace App\Queries;

use App\Enums\WineLotStatus;
use App\Models\EnologicalProduct;
use App\Models\ProtocolAdditive;
use App\Models\WineLot;
use App\Services\Cellar\ProtocolDose;

/**
 * "Protocol needs / deficits": for every fermenting or ageing lot with a
 * protocol, what its additive doses come to (dose × basis on the lot's
 * litres or grape weight), summed per product and set against stock — what
 * is short, and from which supplier. Uses the structured protocol doses (the
 * prototype parsed product names out of the stage text and assumed per-litre).
 */
class ProtocolNeedsQuery
{
    /**
     * @return list<array<string, mixed>>
     */
    public function get(): array
    {
        $lots = WineLot::query()
            ->with('fermentationTemplate.additives.enologicalProduct.supplier')
            ->whereIn('status', [WineLotStatus::Fermenting->value, WineLotStatus::Aging->value])
            ->whereNotNull('fermentation_template_id')
            ->get();

        /** @var array<string, array<string, mixed>> $needs */
        $needs = [];
        foreach ($lots as $lot) {
            $template = $lot->fermentationTemplate;
            if ($template === null) {
                continue;
            }
            foreach ($template->additives as $additive) {
                $qty = ProtocolDose::quantity(
                    $additive->basis,
                    (float) $additive->dose,
                    (float) $lot->current_volume,
                    $lot->harvest_weight_kg !== null ? (float) $lot->harvest_weight_kg : null,
                );
                if ($qty === null || $qty <= 0) {
                    continue;
                }

                $product = $additive->enologicalProduct;
                $key = $product !== null ? 'p:'.$product->getKey() : 'n:'.mb_strtolower($additive->name);
                $needs[$key] ??= $this->entry($additive, $product);
                $needs[$key]['total_needed'] += $qty;
                $needs[$key]['lots'][] = [
                    'wine_lot_id' => (string) $lot->getKey(),
                    'lot_number' => $lot->lot_number,
                    'name' => $lot->name,
                    'volume' => (string) $lot->current_volume,
                    'needed' => $qty,
                ];
            }
        }

        $rows = [];
        foreach ($needs as $row) {
            $row['total_needed'] = round($row['total_needed'], 2);
            $row['deficit'] = round(max(0.0, $row['total_needed'] - (float) $row['current_stock']), 2);
            $rows[] = $row;
        }

        usort($rows, fn (array $a, array $b): int => [$b['deficit'] > 0, $a['product_name']] <=> [$a['deficit'] > 0, $b['product_name']]);

        return $rows;
    }

    /**
     * @return array<string, mixed>
     */
    private function entry(ProtocolAdditive $additive, ?EnologicalProduct $product): array
    {
        return [
            'enological_product_id' => $product?->getKey(),
            'product_name' => $product !== null ? $product->name : $additive->name,
            'unit' => $product !== null ? $product->unit : 'g',
            'current_stock' => $product !== null ? (string) $product->current_stock : '0.000',
            'cost_per_unit' => $product?->cost_per_unit?->getMinorAmount(),
            'supplier' => $product?->supplier !== null ? ['id' => $product->supplier->getKey(), 'name' => $product->supplier->company_name] : null,
            'total_needed' => 0.0,
            'deficit' => 0.0,
            'lots' => [],
        ];
    }
}
