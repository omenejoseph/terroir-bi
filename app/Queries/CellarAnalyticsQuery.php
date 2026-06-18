<?php

declare(strict_types=1);

namespace App\Queries;

use App\Enums\WineLotStatus;
use App\Models\WineLot;
use Illuminate\Database\Eloquent\Builder;

/**
 * Cellar analytics: current volume grouped by vintage / wine type / grape, and a
 * status pipeline. Aggregated in SQL (tenant-scoped via the model).
 */
class CellarAnalyticsQuery
{
    /**
     * @return array{by_vintage: list<array{label: string, volume: float}>, by_type: list<array{label: string, volume: float}>, by_grape: list<array{label: string, volume: float}>, pipeline: list<array{status: string, count: int, volume: float}>}
     */
    public function get(): array
    {
        return [
            'by_vintage' => $this->volumeBy('vintage'),
            'by_type' => $this->volumeBy('wine_type'),
            'by_grape' => $this->volumeBy('grape_variety', 8),
            'pipeline' => $this->pipeline(),
        ];
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
