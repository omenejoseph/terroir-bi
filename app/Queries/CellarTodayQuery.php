<?php

declare(strict_types=1);

namespace App\Queries;

use App\DataTransferObjects\CellarActivityData;
use App\Enums\CellarActivityStatus;
use App\Enums\CellarActivityType;
use App\Models\CellarActivity;
use App\Models\CellarActivityRound;
use App\Services\Cellar\CellarClock;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Cellar › Today: what the team should do now (planned for today or overdue)
 * plus what was done or skipped today, grouped by room in walking order
 * (vessel name). "Mine" is work assigned to me or to nobody.
 *
 * Round progress counts the whole round (done and skipped vs total), not just
 * the rows on screen, and is keyed by round id.
 */
class CellarTodayQuery
{
    public function __construct(private readonly CellarClock $clock) {}

    /**
     * @return array{
     *     today: string,
     *     open: list<array{room: string, items: list<array<string, mixed>>}>,
     *     completed: list<array<string, mixed>>,
     *     rounds: list<array{id: string, name: string, total: int, done: int, skipped: int}>,
     *     counts: array{open: int, mine: int, done_today: int}
     * }
     */
    public function get(string $userId, bool $mineOnly = false): array
    {
        $now = $this->clock->now();
        $today = $now->toDateString();
        $dayStart = $now->startOfDay()->utc();
        $dayEnd = $now->endOfDay()->utc();

        $open = CellarActivity::query()
            ->with(['wineLot', 'vessel', 'round', 'assignedTo', 'executedBy'])
            ->where('status', CellarActivityStatus::Planned->value)
            ->whereDate('planned_for', '<=', $today)
            ->get();

        $openMine = $open->filter(fn (CellarActivity $a) => $a->assigned_to_id === null || $a->assigned_to_id === $userId);
        $visible = $mineOnly ? $openMine : $open;

        $completedQuery = CellarActivity::query()
            ->with(['wineLot', 'vessel', 'round', 'assignedTo', 'executedBy'])
            ->where(fn (Builder $q) => $q
                ->where(fn (Builder $d) => $d->where('status', CellarActivityStatus::Done->value)->whereBetween('applied_at', [$dayStart, $dayEnd]))
                ->orWhere(fn (Builder $s) => $s->where('status', CellarActivityStatus::Skipped->value)->whereBetween('skipped_at', [$dayStart, $dayEnd])))
            ->orderByRaw('coalesce(applied_at, skipped_at) desc');
        if ($mineOnly) {
            $completedQuery->where(fn (Builder $w) => $w->whereNull('assigned_to_id')->orWhere('assigned_to_id', $userId));
        }
        $completed = $completedQuery->get();

        $roundIds = $open->pluck('round_id')->merge($completed->pluck('round_id'))->filter()->unique()->values();

        return [
            'today' => $today,
            'open' => $this->groupByRoom($visible, $today),
            'completed' => array_values($completed->map(fn (CellarActivity $a): array => CellarActivityData::fromModel($a, $today)->toArray())->all()),
            'rounds' => $this->roundProgress($roundIds),
            'counts' => [
                'open' => $open->count(),
                'mine' => $openMine->count(),
                'done_today' => $completed->where('status', CellarActivityStatus::Done)->count(),
            ],
        ];
    }

    /**
     * @param  Collection<int, CellarActivity>  $items
     * @return list<array{room: string, items: list<array<string, mixed>>}>
     */
    private function groupByRoom(Collection $items, string $today): array
    {
        return array_values($items
            ->groupBy(fn (CellarActivity $a): string => $a->vessel !== null
                ? ($a->vessel->room ?: 'Main Cellar')
                : ($a->type === CellarActivityType::Vineyard ? 'Vineyard' : 'Cellar'))
            ->sortKeys(SORT_NATURAL | SORT_FLAG_CASE)
            ->map(fn (Collection $group, string $room): array => [
                'room' => $room,
                'items' => array_values($group
                    ->sortBy([
                        fn (CellarActivity $a, CellarActivity $b): int => strnatcasecmp((string) $a->vessel?->name, (string) $b->vessel?->name),
                        fn (CellarActivity $a, CellarActivity $b): int => $a->planned_for <=> $b->planned_for,
                        fn (CellarActivity $a, CellarActivity $b): int => $a->sort_order <=> $b->sort_order,
                    ])
                    ->map(fn (CellarActivity $a): array => CellarActivityData::fromModel($a, $today)->toArray())
                    ->all()),
            ])
            ->all());
    }

    /**
     * @param  Collection<int, string>  $roundIds
     * @return list<array{id: string, name: string, total: int, done: int, skipped: int}>
     */
    private function roundProgress(Collection $roundIds): array
    {
        if ($roundIds->isEmpty()) {
            return [];
        }

        $names = CellarActivityRound::query()->whereIn('id', $roundIds->all())->pluck('name', 'id');

        // Tenant-scoped through the model's global scope.
        return array_values(CellarActivity::query()
            ->whereIn('round_id', $roundIds->all())
            ->groupBy('round_id')
            ->selectRaw('round_id, count(*) as total')
            ->selectRaw("sum(case when status = 'DONE' then 1 else 0 end) as done")
            ->selectRaw("sum(case when status = 'SKIPPED' then 1 else 0 end) as skipped")
            ->toBase()
            ->get()
            ->map(fn (object $r): array => [
                'id' => (string) $r->round_id,
                'name' => (string) ($names[$r->round_id] ?? ''),
                'total' => (int) $r->total,
                'done' => (int) $r->done,
                'skipped' => (int) $r->skipped,
            ])
            ->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)
            ->all());
    }
}
