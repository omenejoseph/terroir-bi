<?php

declare(strict_types=1);

namespace App\Queries;

use App\Enums\TaskStatus;
use App\Enums\WineLotStatus;
use App\Enums\WorkOrderCategory;
use App\Models\Bottling;
use App\Models\CellarAddition;
use App\Models\CellarAnalysis;
use App\Models\Vessel;
use App\Models\WineLot;
use App\Models\WorkOrder;
use App\Services\Cellar\CellarClock;
use App\Services\Cellar\LotCostService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Cellar analytics: current volume grouped by vintage / wine type / grape, and a
 * status pipeline. Aggregated in SQL (tenant-scoped via the model).
 */
class CellarAnalyticsQuery
{
    /** Days without an SO₂ addition / analysis before a lot is flagged. */
    public const STALE_DAYS = 30;

    public function __construct(
        private readonly FermentationMonitorQuery $monitor,
        private readonly LotCostService $costs,
        private readonly CellarClock $clock,
    ) {}

    /**
     * `$withCosts` adds the average cost per litre (finance.view only).
     *
     * @return array<string, mixed>
     */
    public function get(bool $withCosts = false): array
    {
        $lots = WineLot::query()
            ->where('status', '!=', WineLotStatus::Bottled)
            ->with(['latestAnalysis'])
            ->get();

        return [
            'kpis' => $this->kpis($lots, $withCosts),
            'by_vintage' => $this->volumeBy('vintage'),
            'by_type' => $this->volumeBy('wine_type'),
            'by_grape' => $this->volumeBy('grape_variety', 8),
            'pipeline' => $this->pipeline(),
            'timeline' => $this->timeline(),
            'health' => $this->health($lots),
            'alerts' => $this->monitor->get(),
        ];
    }

    /**
     * @param  Collection<int, WineLot>  $lots
     * @return array<string, int|float|null>
     */
    private function kpis(Collection $lots, bool $withCosts): array
    {
        $volume = (float) $lots->sum(fn (WineLot $l): float => (float) $l->current_volume);
        $since = $this->clock->now()->subDays(self::STALE_DAYS);

        $lastSo2 = CellarAddition::query()
            ->whereIn('wine_lot_id', $lots->modelKeys())
            ->where(fn (Builder $q) => $q
                ->whereIn(DB::raw('upper(category)'), ['SO2', 'SULFITE', 'SULPHITE'])
                ->orWhereRaw('lower(name) like ?', ['%so2%'])
                ->orWhereRaw('lower(name) like ?', ['%metabisulf%']))
            ->groupBy('wine_lot_id')
            ->selectRaw('wine_lot_id, max(created_at) as last_at')
            ->toBase()
            ->pluck('last_at', 'wine_lot_id');

        $needsSo2 = $lots->filter(fn (WineLot $l): bool => ! isset($lastSo2[$l->getKey()]) || Carbon::parse((string) $lastSo2[$l->getKey()])->lessThan($since))->count();
        $needsAnalysis = $lots->filter(fn (WineLot $l): bool => $l->latestAnalysis === null || $l->latestAnalysis->date->lessThan($since))->count();

        $vessels = Vessel::query()->where('is_active', true)->get(['capacity_liters', 'current_volume']);
        $capacity = (float) $vessels->sum(fn (Vessel $v): float => (float) $v->capacity_liters);

        $avgCost = null;
        if ($withCosts && $volume > 0) {
            $weighted = $lots->sum(fn (WineLot $l): float => $this->costs->breakdown($l)['per_liter'] * (float) $l->current_volume);
            $avgCost = (int) round($weighted / $volume);
        }

        return [
            'wine_in_cellar' => round($volume, 1),
            'ready_to_bottle' => round((float) $lots->where('status', WineLotStatus::Ready)->sum(fn (WineLot $l): float => (float) $l->current_volume), 1),
            'estimated_bottles' => (int) floor($volume / 0.75),
            'average_cost_per_liter' => $avgCost,
            'overdue_tasks' => WorkOrder::query()
                ->where('category', WorkOrderCategory::Cellar->value)
                ->whereNotIn('status', array_map(fn (TaskStatus $s): string => $s->value, TaskStatus::closed()))
                ->whereDate('due_date', '<', $this->clock->today())
                ->count(),
            'needs_so2' => $needsSo2,
            'needs_analysis' => $needsAnalysis,
            'vessel_utilisation' => $capacity > 0 ? round((float) $vessels->sum(fn (Vessel $v): float => (float) $v->current_volume) / $capacity * 100, 1) : null,
            'empty_vessels' => $vessels->filter(fn (Vessel $v): bool => (float) $v->current_volume <= 0.001)->count(),
        ];
    }

    /**
     * Litres in (new lots) and out (bottled) per month, last 12 months.
     *
     * @return list<array{month: string, in: float, out: float}>
     */
    private function timeline(): array
    {
        $start = $this->clock->now()->startOfMonth()->subMonths(11);
        $months = [];
        for ($i = 0; $i < 12; $i++) {
            $months[$start->addMonths($i)->format('Y-m')] = ['in' => 0.0, 'out' => 0.0];
        }

        foreach (WineLot::query()->where('created_at', '>=', $start)->where('lot_number', 'not like', 'BLEND-%')->get(['created_at', 'initial_volume']) as $lot) {
            $key = $lot->created_at?->format('Y-m');
            if ($key !== null && isset($months[$key])) {
                $months[$key]['in'] += (float) $lot->initial_volume;
            }
        }
        foreach (Bottling::query()->where('date', '>=', $start)->get(['date', 'volume_used']) as $b) {
            $key = $b->date->format('Y-m');
            if (isset($months[$key])) {
                $months[$key]['out'] += (float) $b->volume_used;
            }
        }

        $out = [];
        foreach ($months as $month => $v) {
            $out[] = ['month' => $month, 'in' => round($v['in'], 1), 'out' => round($v['out'], 1)];
        }

        return $out;
    }

    /**
     * The lot-health rows on their own (for the cellar's attention list).
     *
     * @return list<array<string, mixed>>
     */
    public function lotHealth(): array
    {
        return $this->health(WineLot::query()->where('status', '!=', WineLotStatus::Bottled)->with(['latestAnalysis'])->get());
    }

    /**
     * Per-lot traffic lights: VA above 0.7, free SO₂ below 20, and the age of
     * the last analysis and of the last SO₂ reading.
     *
     * @param  Collection<int, WineLot>  $lots
     * @return list<array<string, mixed>>
     */
    private function health(Collection $lots): array
    {
        $today = $this->clock->now();
        $so2 = CellarAnalysis::query()
            ->whereIn('wine_lot_id', $lots->modelKeys())
            ->whereNotNull('free_so2')
            ->groupBy('wine_lot_id')
            ->selectRaw('wine_lot_id, max(date) as last_at')
            ->toBase()
            ->pluck('last_at', 'wine_lot_id');

        $rows = [];
        foreach ($lots as $lot) {
            $a = $lot->latestAnalysis;
            $va = $a?->volatile_acidity !== null ? (float) $a->volatile_acidity : null;
            $free = $a?->free_so2 !== null ? (float) $a->free_so2 : null;
            $analysisAge = $a !== null ? (int) $a->date->diffInDays($today) : null;
            $so2Age = isset($so2[$lot->getKey()]) ? (int) Carbon::parse((string) $so2[$lot->getKey()])->diffInDays($today) : null;

            $flags = array_values(array_filter([
                $va !== null && $va > 0.7 ? 'va_high' : null,
                $free !== null && $free < 20 ? 'so2_low' : null,
                $analysisAge === null || $analysisAge >= self::STALE_DAYS ? 'analysis_stale' : null,
                $so2Age === null || $so2Age >= self::STALE_DAYS ? 'so2_stale' : null,
            ]));

            $rows[] = [
                'wine_lot_id' => (string) $lot->getKey(),
                'lot_number' => $lot->lot_number,
                'name' => $lot->name,
                'volatile_acidity' => $va,
                'free_so2' => $free,
                'analysis_age_days' => $analysisAge,
                'so2_age_days' => $so2Age,
                'flags' => $flags,
                'level' => in_array('va_high', $flags, true) || in_array('so2_low', $flags, true) ? 'red' : ($flags !== [] ? 'amber' : 'green'),
            ];
        }

        return $rows;
    }

    /**
     * @return list<array{label: string, volume: float}>
     */
    private function volumeBy(string $column, ?int $limit = null): array
    {
        $rows = WineLot::query()
            ->where('status', '!=', WineLotStatus::Bottled)
            ->addSelect($column)
            ->selectRaw('SUM(current_volume) as volume')
            ->groupBy($column)
            ->orderByDesc('volume')
            ->when($limit !== null, fn (Builder $q) => $q->limit((int) $limit))
            ->get();

        $out = $rows->map(fn ($r): array => [
            'label' => $this->label($r->getAttribute($column)),
            'volume' => round((float) $r->getAttribute('volume'), 2),
        ])->all();

        return array_values($out);
    }

    /** Stringify a grouped value that may be a backed enum, null, or scalar. */
    private function label(mixed $value): string
    {
        if ($value instanceof \BackedEnum) {
            return (string) $value->value;
        }

        return $value === null || $value === '' ? '—' : (string) $value;
    }

    /**
     * @return list<array{status: string, count: int, volume: float}>
     */
    private function pipeline(): array
    {
        $rows = WineLot::query()
            ->addSelect('status')
            ->selectRaw('COUNT(*) as cnt, SUM(current_volume) as volume')
            ->groupBy('status')
            ->get();

        $out = $rows->map(fn ($r): array => [
            'status' => $this->label($r->getAttribute('status')),
            'count' => (int) $r->getAttribute('cnt'),
            'volume' => round((float) $r->getAttribute('volume'), 2),
        ])->all();

        return array_values($out);
    }
}
