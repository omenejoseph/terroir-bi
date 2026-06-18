<?php

declare(strict_types=1);

namespace App\Actions\Cellar;

use App\Enums\WorkOrderCategory;
use App\Models\WineLot;
use App\Models\WorkOrder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Turn a lot's assigned fermentation protocol into today's work orders. Computes
 * the day of fermentation from the lot's creation date, finds the stages active
 * today, and creates one work order per scheduled action — idempotently (an
 * action already raised for this lot today is skipped).
 *
 * @return array{created: int, skipped: int, day: int, active_stages: list<string>}
 */
class GenerateWorkOrdersFromProtocolAction
{
    /**
     * @return array{created: int, skipped: int, day: int, active_stages: list<string>}
     */
    public function execute(WineLot $lot, string $createdById): array
    {
        $template = $lot->fermentationTemplate;
        if ($template === null) {
            throw ValidationException::withMessages([
                'fermentation_template_id' => 'Assign a fermentation protocol first.',
            ]);
        }

        $start = $lot->created_at ?? Carbon::now();
        $day = (int) $start->copy()->startOfDay()->diffInDays(Carbon::now()->startOfDay());
        $today = Carbon::now()->startOfDay();

        $created = 0;
        $skipped = 0;
        $activeStages = [];

        return DB::transaction(function () use ($lot, $template, $day, $today, $createdById, &$created, &$skipped, &$activeStages): array {
            foreach ($template->stages ?? [] as $stage) {
                $dayStart = (int) ($stage['dayStart'] ?? $stage['day_start'] ?? 0);
                $dayEnd = (int) ($stage['dayEnd'] ?? $stage['day_end'] ?? 0);
                if ($day < $dayStart || $day > $dayEnd) {
                    continue;
                }

                $stageName = (string) ($stage['name'] ?? 'Stage');
                $activeStages[] = $stageName;

                /** @var list<array<string, mixed>> $actions */
                $actions = $stage['actions'] ?? [];
                foreach ($actions as $action) {
                    $label = (string) ($action['description'] ?? $action['type'] ?? 'Action');
                    $title = "[{$lot->lot_number}] {$stageName} — {$label}";

                    $exists = WorkOrder::query()
                        ->where('wine_lot_id', $lot->getKey())
                        ->where('title', $title)
                        ->whereDate('due_date', $today->toDateString())
                        ->exists();

                    if ($exists) {
                        $skipped++;

                        continue;
                    }

                    WorkOrder::create([
                        'title' => $title,
                        'category' => WorkOrderCategory::Cellar,
                        'wine_lot_id' => $lot->getKey(),
                        'due_date' => $today,
                        'created_by_id' => $createdById,
                    ]);
                    $created++;
                }
            }

            return [
                'created' => $created,
                'skipped' => $skipped,
                'day' => $day,
                'active_stages' => array_values(array_unique($activeStages)),
            ];
        });
    }
}
