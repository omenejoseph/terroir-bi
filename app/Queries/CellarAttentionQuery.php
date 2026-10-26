<?php

declare(strict_types=1);

namespace App\Queries;

use App\Enums\CellarActivityStatus;
use App\Enums\CellarActivityType;
use App\Enums\WineLotStatus;
use App\Models\CellarActivity;
use App\Models\VesselLot;
use App\Models\WineLot;
use App\Services\Cellar\CellarClock;
use Illuminate\Support\Collection;

/**
 * What in the cellar needs someone now, where people work (the cellar home,
 * and each lot's page): the fermentation monitor's alerts, the lot-health
 * flags (low or unread free SO₂, high VA) and overdue planned work — each
 * with the one action that deals with it. Informational alerts (MLF or
 * fermentation looks complete) stay on Analytics. Most urgent first.
 *
 * An action either links somewhere or plans work (the same POST the planner
 * makes); when the reading it would plan is already planned, it says so and
 * links to Today instead of planning it twice.
 *
 * @phpstan-type Action array{kind: string, label: string, href: string|null, post: array{url: string, data: array<string, mixed>}|null}
 * @phpstan-type Item array{key: string, lot_id: string, lot_name: string, severity: string, message: string, suggestion: string|null, action: Action}
 */
class CellarAttentionQuery
{
    private const RANK = ['critical' => 0, 'high' => 1, 'medium' => 2];

    /** @var list<Item> */
    private array $items = [];

    /** @var array<string, string> lot id → name */
    private array $names = [];

    /** @var array<string, list<string>> lot id → vessel ids */
    private array $vessels = [];

    /** @var array<string, string> lot id → earliest planned reading (Y-m-d) */
    private array $plannedReading = [];

    private string $today = '';

    public function __construct(
        private readonly FermentationMonitorQuery $monitor,
        private readonly CellarAnalyticsQuery $analytics,
        private readonly CellarClock $clock,
    ) {}

    /**
     * @return list<Item>
     */
    public function get(?string $lotId = null): array
    {
        $this->items = [];
        $this->today = $this->clock->today();
        $this->names = $this->labels(WineLot::query()->where('status', '!=', WineLotStatus::Bottled)
            ->when($lotId !== null, fn ($q) => $q->whereKey($lotId))
            ->get(['id', 'name', 'vintage', 'lot_number']));
        if ($this->names === []) {
            return [];
        }
        $ids = array_keys($this->names);

        $this->vessels = [];
        foreach (VesselLot::query()->whereIn('wine_lot_id', $ids)->get(['wine_lot_id', 'vessel_id']) as $vl) {
            $this->vessels[(string) $vl->wine_lot_id][] = (string) $vl->vessel_id;
        }

        $planned = CellarActivity::query()->whereIn('wine_lot_id', $ids)->where('status', CellarActivityStatus::Planned->value)->get();
        $this->plannedReading = [];
        foreach ($planned as $a) {
            $lot = (string) $a->wine_lot_id;
            if ($a->type === CellarActivityType::Analysis && (! isset($this->plannedReading[$lot]) || $a->planned_for->toDateString() < $this->plannedReading[$lot])) {
                $this->plannedReading[$lot] = $a->planned_for->toDateString();
            }
        }

        foreach ($this->monitor->get() as $alert) {
            if (! isset(self::RANK[$alert['severity']])) {
                continue; // info: shown on Analytics, not a call to act
            }
            $lot = $alert['lot_id'];
            $suggestion = rtrim($alert['suggestion'], '.');
            $action = match ($alert['type']) {
                'no_analysis', 'stale_analysis' => $this->planReading($lot, ['free_so2', 'total_so2', 'ph']),
                'temperature' => $this->post('process_task', $suggestion, [[
                    'type' => CellarActivityType::Process->value, 'planned_for' => $this->today, 'wine_lot_id' => $lot,
                    'vessel_id' => $this->vessels[$lot][0] ?? null, 'payload' => ['kind' => $suggestion],
                ]]),
                default => $this->link('open_lot', __('Open the lot'), "/cellar/lots/{$lot}"),
            };
            $this->add($lot, $alert['type'], $alert['severity'], $alert['message'], $alert['type'] === 'temperature' ? null : $alert['suggestion'], $action);
        }

        foreach ($this->analytics->lotHealth() as $row) {
            $lot = (string) $row['wine_lot_id'];
            /** @var list<string> $flags */
            $flags = $row['flags'];
            if (in_array('so2_low', $flags, true)) {
                $this->add($lot, 'so2_low', 'high', __('Free SO₂ :free mg/L is low.', ['free' => $row['free_so2']]), __('Bring it up before the wine oxidises.'),
                    $this->link('plan_so2', __('Plan SO₂'), "/cellar/so2?lots={$lot}"));
            }
            if (in_array('so2_stale', $flags, true) && ! $this->has($lot, 'stale_analysis') && ! $this->has($lot, 'no_analysis')) {
                $this->add($lot, 'so2_stale', 'medium', $row['so2_age_days'] === null ? __('No SO₂ reading yet.') : __('No SO₂ reading in :n days.', ['n' => $row['so2_age_days']]), null,
                    $this->planReading($lot, ['free_so2', 'total_so2']));
            }
            if (in_array('va_high', $flags, true)) {
                $this->add($lot, 'volatile_acidity', 'high', __('Volatile acidity :va g/L is high.', ['va' => $row['volatile_acidity']]), __('Check for acetobacter.'),
                    $this->link('open_lot', __('Open the lot'), "/cellar/lots/{$lot}"));
            }
        }

        $overdue = [];
        foreach ($planned as $a) {
            if ($a->planned_for->toDateString() < $this->today) {
                $overdue[(string) $a->wine_lot_id][] = $a->planned_for->toDateString();
            }
        }
        foreach ($overdue as $lot => $dates) {
            $this->add((string) $lot, 'overdue', 'medium', __('Planned work overdue since :date (:n).', ['n' => count($dates), 'date' => min($dates)]), null,
                $this->link('open_today', __('Open Today'), '/cellar/today'));
        }

        $items = $this->items;
        usort($items, fn (array $a, array $b): int => [self::RANK[$a['severity']] ?? 9, $a['lot_name']] <=> [self::RANK[$b['severity']] ?? 9, $b['lot_name']]);

        return $items;
    }

    /**
     * Each lot's display name, unique within the list: lots often share a wine name ("Aleph" in five
     * vintages), so the vintage is appended (as the rest of the cellar writes it — "Aleph 2022"), and
     * the lot number too when two lots would still read the same.
     *
     * @param  Collection<int, WineLot>  $lots
     * @return array<string, string> lot id → label
     */
    private function labels($lots): array
    {
        $base = [];
        foreach ($lots as $lot) {
            $vintage = (string) ($lot->vintage ?? '');
            $base[(string) $lot->getKey()] = $vintage === '' || str_contains($lot->name, $vintage) ? $lot->name : "{$lot->name} {$vintage}";
        }

        $count = array_count_values($base);
        $out = [];
        foreach ($lots as $lot) {
            $id = (string) $lot->getKey();
            $out[$id] = $count[$base[$id]] > 1 && (string) $lot->lot_number !== '' ? "{$base[$id]} · {$lot->lot_number}" : $base[$id];
        }

        return $out;
    }

    private function has(string $lot, string $key): bool
    {
        foreach ($this->items as $item) {
            if ($item['key'] === "{$lot}:{$key}") {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  Action  $action
     */
    private function add(string $lot, string $key, string $severity, string $message, ?string $suggestion, array $action): void
    {
        if (! isset($this->names[$lot]) || $this->has($lot, $key)) {
            return;
        }
        $this->items[] = [
            'key' => "{$lot}:{$key}",
            'lot_id' => $lot,
            'lot_name' => $this->names[$lot],
            'severity' => $severity,
            'message' => $message,
            'suggestion' => $suggestion,
            'action' => $action,
        ];
    }

    /**
     * @param  list<string>  $metrics
     * @return Action
     */
    private function planReading(string $lot, array $metrics): array
    {
        if (isset($this->plannedReading[$lot])) {
            return $this->link('open_today', __('Reading planned · :date', ['date' => $this->plannedReading[$lot]]), '/cellar/today');
        }

        return $this->post('plan_reading', __('Plan a reading'), array_map(fn (?string $v): array => [
            'type' => CellarActivityType::Analysis->value, 'planned_for' => $this->today, 'wine_lot_id' => $lot, 'vessel_id' => $v,
            'payload' => ['metrics' => $metrics],
        ], $this->vessels[$lot] ?? [null]));
    }

    /** @return Action */
    private function link(string $kind, string $label, string $href): array
    {
        return ['kind' => $kind, 'label' => $label, 'href' => $href, 'post' => null];
    }

    /**
     * @param  list<array<string, mixed>>  $activities
     * @return Action
     */
    private function post(string $kind, string $label, array $activities): array
    {
        return ['kind' => $kind, 'label' => $label, 'href' => null, 'post' => ['url' => '/cellar/activities', 'data' => ['activities' => $activities]]];
    }
}
