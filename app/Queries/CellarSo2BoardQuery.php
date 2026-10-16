<?php

declare(strict_types=1);

namespace App\Queries;

use App\Enums\WineLotStatus;
use App\Models\CellarAnalysis;
use App\Models\EnologicalProduct;
use App\Models\VesselLot;
use App\Models\WineLot;
use App\Services\Cellar\CellarClock;
use App\Services\Cellar\LatestAnalyses;
use App\Services\Cellar\So2Calculator;
use App\Services\Cellar\WineCompliance;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Everything the Cellar › SO₂ screen shows: each unbottled lot with the
 * latest free/total SO₂ for the lot and for each vessel holding it, the
 * status bucket, a reference target, molecular SO₂ and the EU total-SO₂
 * check, plus the SO₂ products to dose with.
 *
 * "Latest" is per (lot, vessel) — a whole-cellar update writes one reading per
 * vessel, so a lot spanning many vessels must not lose any of them. A vessel
 * without its own reading shows the lot's (`inherited: true`).
 */
class CellarSo2BoardQuery
{
    public function __construct(
        private readonly So2Calculator $so2,
        private readonly WineCompliance $compliance,
        private readonly CellarClock $clock,
        private readonly LatestAnalyses $latest,
    ) {}

    /**
     * @return array{
     *     lots: list<array<string, mixed>>,
     *     summary: array{critical: int, low: int, ok: int, high: int, no_data: int, total: int},
     *     products: list<array<string, mixed>>
     * }
     */
    public function get(): array
    {
        $lots = WineLot::query()
            ->with('vesselLots.vessel')
            ->whereIn('status', [WineLotStatus::Fermenting->value, WineLotStatus::Aging->value, WineLotStatus::Ready->value])
            ->orderByDesc('vintage')
            ->orderBy('name')
            ->get();

        $lotIds = array_values(array_map(fn (int|string $id): string => (string) $id, $lots->modelKeys()));
        $so2 = $this->latest->perVessel($lotIds, 'free_so2');
        $ph = $this->latest->perLot($lotIds, 'ph');
        $sugar = $this->latest->perLot($lotIds, 'residual_sugar');
        $today = $this->clock->now()->startOfDay();

        $rows = [];

        foreach ($lots as $lot) {
            /** @var WineLot $lot */
            $key = (string) $lot->getKey();
            $lotPh = isset($ph[$key]) ? (float) $ph[$key]->ph : null;
            $lotSugar = isset($sugar[$key]) ? (float) $sugar[$key]->residual_sugar : null;

            // The lot-level reading (else the newest across its vessels) — what a vessel without its own inherits.
            $byVessel = $so2[$key] ?? [];
            $lotReading = $this->latest->lotReading($byVessel);

            $vessels = [];
            $effective = [];
            $firstOak = false;
            foreach ($lot->vesselLots->sortBy(fn (VesselLot $vl) => (string) $vl->vessel?->name, SORT_NATURAL | SORT_FLAG_CASE) as $vl) {
                $vessel = $vl->vessel;
                if ($vessel === null) {
                    continue;
                }
                $own = $byVessel[(string) $vl->vessel_id] ?? null;
                if (($own ?? $lotReading) !== null) {
                    $effective[] = $own ?? $lotReading;
                }
                $oak = $this->so2->isOak($vessel->material, $vessel->type);
                $firstOak = $vessels === [] ? $oak : $firstOak;

                $vessels[] = [
                    'id' => (string) $vessel->getKey(),
                    'name' => $vessel->name,
                    'type' => $vessel->type->value,
                    'material' => $vessel->material,
                    'room' => $vessel->room,
                    'oak' => $oak,
                    'volume' => (string) $vl->volume,
                    'reference_target' => $this->so2->referenceTarget($lot->wine_type, $oak),
                    'inherited' => $own === null && $lotReading !== null,
                    ...$this->reading($own ?? $lotReading, $lotPh, $today),
                ];
            }

            // The lot row shows its worst vessel — the lowest free SO₂ any vessel is at (the one
            // that gets dosed, and the one at risk); a lot without vessels shows its own reading.
            // Doses are planned per vessel from these same readings, so the row and the dose agree.
            $withFree = array_values(array_filter($effective, fn (CellarAnalysis $a): bool => $a->free_so2 !== null));
            usort($withFree, fn (CellarAnalysis $a, CellarAnalysis $b): int => (float) $a->free_so2 <=> (float) $b->free_so2);
            $shown = $withFree[0] ?? $lotReading;
            $reading = $this->reading($shown, $lotPh, $today);
            // EU limits apply to the highest total SO₂ in the lot.
            $totals = array_values(array_filter(array_map(fn (CellarAnalysis $a): ?float => $a->total_so2 !== null ? (float) $a->total_so2 : null, [...$effective, ...($lotReading !== null ? [$lotReading] : [])]), fn (?float $v): bool => $v !== null));
            $totalSo2 = $totals === [] ? null : max($totals);

            $rows[] = [
                'id' => $key,
                'lot_number' => $lot->lot_number,
                'name' => $lot->name,
                'grape_variety' => $lot->grape_variety,
                'vintage' => $lot->vintage,
                'wine_type' => $lot->wine_type?->value,
                'status' => $lot->status->value,
                'current_volume' => (string) $lot->current_volume,
                'ph' => $lotPh,
                'residual_sugar' => $lotSugar,
                'reference_target' => $this->so2->referenceTarget($lot->wine_type, $firstOak),
                'compliance' => $totalSo2 !== null ? $this->compliance->checkSo2($totalSo2, $lot->wine_type, $lotSugar) : null,
                ...$reading,
                'vessels' => $vessels,
            ];
        }

        $statuses = array_column($rows, 'so2_status');
        $count = fn (string $s): int => count(array_keys($statuses, $s, true));

        return [
            'lots' => $rows,
            'summary' => [
                'critical' => $count('critical'),
                'low' => $count('low'),
                'ok' => $count('ok'),
                'high' => $count('high'),
                'no_data' => $count('no_data'),
                'total' => count($rows),
            ],
            'products' => $this->products(),
        ];
    }

    /**
     * @return array{free_so2: float|null, total_so2: float|null, reading_date: string|null, days_since: int|null, so2_status: string, molecular: float|null}
     */
    private function reading(?CellarAnalysis $a, ?float $ph, CarbonImmutable $today): array
    {
        $free = $a?->free_so2 !== null ? (float) $a->free_so2 : null;

        return [
            'free_so2' => $free,
            'total_so2' => $a?->total_so2 !== null ? (float) $a->total_so2 : null,
            'reading_date' => $a?->date->toIso8601String(),
            'days_since' => $a !== null ? (int) $a->date->copy()->setTimezone($today->getTimezone())->startOfDay()->diffInDays($today) : null,
            'so2_status' => $this->so2->status($free),
            'molecular' => $free !== null && $ph !== null ? $this->so2->molecular($free, $ph) : null,
        ];
    }

    /**
     * SO₂ products: category SO2 / SULFITE, or a name that says so; when none
     * match, every active product (so a winery with odd naming still works).
     *
     * @return list<array<string, mixed>>
     */
    private function products(): array
    {
        $matching = EnologicalProduct::query()
            ->where('is_active', true)
            ->where(fn (Builder $q) => $q
                ->whereIn('category', ['SO2', 'SULFITE', 'SULPHITE'])
                ->orWhereRaw('lower(name) like ?', ['%so2%'])
                ->orWhereRaw('lower(name) like ?', ['%metabisulf%'])
                ->orWhereRaw('lower(name) like ?', ['%sulfit%'])
                ->orWhereRaw('lower(name) like ?', ['%sumpor%']))
            ->orderBy('name')
            ->get();

        $products = $matching->isNotEmpty()
            ? $matching
            : EnologicalProduct::query()->where('is_active', true)->orderBy('category')->orderBy('name')->get();

        return $this->presentProducts($products);
    }

    /**
     * @param  Collection<int, EnologicalProduct>  $products
     * @return list<array<string, mixed>>
     */
    private function presentProducts(Collection $products): array
    {
        return array_values($products->map(fn (EnologicalProduct $p): array => [
            'id' => $p->getKey(),
            'name' => $p->name,
            'category' => $p->category,
            'unit' => $p->unit,
            'current_stock' => (string) $p->current_stock,
            'cost_per_unit' => $p->cost_per_unit?->jsonSerialize(),
            'so2_uplift_per_unit' => $p->so2_uplift_per_unit !== null ? (string) $p->so2_uplift_per_unit : null,
        ])->all());
    }
}
