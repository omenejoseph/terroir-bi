<?php

declare(strict_types=1);

namespace App\Queries;

use App\DataTransferObjects\CellarActivityData;
use App\Enums\CellarActivityStatus;
use App\Models\CellarActivity;
use App\Services\Cellar\CellarClock;
use Illuminate\Support\Collection;

/**
 * Cellar › Plan: the open plan grouped by day → round (rounds first, then
 * single items), and the recently executed work grouped the same way.
 *
 * "Recently executed" is the last N done or skipped items by when they were
 * done — the prototype filtered by planned date, which hid work that was
 * planned long ago but done today.
 */
class CellarPlanQuery
{
    public function __construct(private readonly CellarClock $clock) {}

    /**
     * @return array{
     *     today: string,
     *     open: list<array{date: string, rounds: list<array<string, mixed>>, singles: list<array<string, mixed>>}>,
     *     executed: list<array{date: string, rounds: list<array<string, mixed>>, singles: list<array<string, mixed>>}>
     * }
     */
    public function get(int $executedLimit = 30): array
    {
        $today = $this->clock->today();
        $with = ['wineLot', 'vessel', 'round', 'assignedTo', 'executedBy'];

        $open = CellarActivity::query()
            ->with($with)
            ->where('status', CellarActivityStatus::Planned->value)
            ->orderBy('planned_for')
            ->orderBy('sort_order')
            ->get();

        $executed = CellarActivity::query()
            ->with($with)
            ->where('status', '!=', CellarActivityStatus::Planned->value)
            ->orderByRaw('coalesce(applied_at, skipped_at) desc')
            ->limit($executedLimit)
            ->get();

        $tz = $this->clock->timezone();

        return [
            'today' => $today,
            'open' => $this->byDay($open, fn (CellarActivity $a): string => $a->planned_for->toDateString(), $today),
            'executed' => $this->byDay(
                $executed,
                fn (CellarActivity $a): string => (string) ($a->applied_at ?? $a->skipped_at)?->setTimezone($tz)->toDateString(),
                $today,
            ),
        ];
    }

    /**
     * @param  Collection<int, CellarActivity>  $items
     * @param  callable(CellarActivity): string  $day
     * @return list<array{date: string, rounds: list<array<string, mixed>>, singles: list<array<string, mixed>>}>
     */
    private function byDay(Collection $items, callable $day, string $today): array
    {
        $out = [];
        foreach ($items->groupBy($day) as $date => $group) {
            /** @var Collection<int, CellarActivity> $group */
            $rounds = $group->whereNotNull('round_id')
                ->groupBy('round_id')
                ->map(function (Collection $roundItems) use ($today): array {
                    /** @var CellarActivity $first */
                    $first = $roundItems->first();

                    return [
                        'id' => $first->round_id,
                        'name' => $first->round?->name,
                        'done' => $roundItems->where('status', CellarActivityStatus::Done)->count(),
                        'skipped' => $roundItems->where('status', CellarActivityStatus::Skipped)->count(),
                        'deviated' => $roundItems->where('deviated', true)->count(),
                        'executed_by' => array_values($roundItems->map(fn (CellarActivity $a): ?string => $a->executedBy?->fullName())->filter()->unique()->all()),
                        'items' => array_values($roundItems->map(fn (CellarActivity $a): array => CellarActivityData::fromModel($a, $today)->toArray())->all()),
                    ];
                })
                ->values()
                ->all();

            $out[] = [
                'date' => (string) $date,
                'rounds' => array_values($rounds),
                'singles' => array_values($group->whereNull('round_id')
                    ->map(fn (CellarActivity $a): array => CellarActivityData::fromModel($a, $today)->toArray())
                    ->all()),
            ];
        }

        return $out;
    }
}
