<?php

declare(strict_types=1);

namespace App\Queries;

use App\Enums\WineLotStatus;
use App\Enums\WineType;
use App\Models\CellarAnalysis;
use App\Models\WineLot;

/**
 * Scans active (non-bottled) lots and raises fermentation/quality alerts from
 * their latest analyses: stuck fermentation, out-of-band temperature, high
 * volatile acidity, high pH, completed MLF, completed fermentation, and stale or
 * missing analyses. Pure read — no writes.
 *
 * @phpstan-type Alert array{type: string, severity: string, lot_id: string, lot_name: string, message: string, suggestion: string}
 */
class FermentationMonitorQuery
{
    /**
     * @return list<Alert>
     */
    public function get(): array
    {
        $alerts = [];

        $lots = WineLot::query()
            ->where('status', '!=', WineLotStatus::Bottled)
            ->with(['analyses' => fn ($q) => $q->orderByDesc('date')->limit(2)])
            ->get();

        foreach ($lots as $lot) {
            $latest = $lot->analyses->first();

            if ($latest === null) {
                if ($lot->created_at !== null && $lot->created_at->diffInDays(now()) > 1) {
                    $alerts[] = $this->alert('no_analysis', 'medium', $lot,
                        'No analysis recorded yet.', 'Record a baseline analysis.');
                }

                continue;
            }

            foreach ([
                $this->staleAlert($lot, $latest),
                $this->stuckAlert($lot, $lot->analyses->get(1), $latest),
                $this->temperatureAlert($lot, $latest),
                $this->volatileAcidityAlert($lot, $latest),
                $this->phAlert($lot, $latest),
                $this->mlfAlert($lot, $latest),
                $this->completeAlert($lot, $latest),
            ] as $alert) {
                if ($alert !== null) {
                    $alerts[] = $alert;
                }
            }
        }

        return $alerts;
    }

    /** @return Alert|null */
    private function staleAlert(WineLot $lot, CellarAnalysis $latest): ?array
    {
        $days = (int) $latest->date->diffInDays(now());
        if ($days > 10) {
            return $this->alert('stale_analysis', 'high', $lot, "No analysis in {$days} days.", 'Take a fresh sample.');
        }
        if ($days > 5) {
            return $this->alert('stale_analysis', 'info', $lot, "Last analysis was {$days} days ago.", 'Consider sampling again.');
        }

        return null;
    }

    /** @return Alert|null */
    private function stuckAlert(WineLot $lot, ?CellarAnalysis $prev, CellarAnalysis $latest): ?array
    {
        if ($prev === null || $latest->brix === null || $prev->brix === null) {
            return null;
        }
        $drop = (float) $prev->brix - (float) $latest->brix;
        if ($drop < 0.5 && (float) $latest->brix > 2) {
            return $this->alert('stuck_fermentation', 'high', $lot,
                'Sugar is barely dropping — fermentation may be stuck.',
                'Check temperature and nutrients; consider a restart.');
        }

        return null;
    }

    /** @return Alert|null */
    private function temperatureAlert(WineLot $lot, CellarAnalysis $latest): ?array
    {
        if ($latest->temperature === null) {
            return null;
        }
        $t = (float) $latest->temperature;
        $isRed = $lot->wine_type === WineType::Red;
        [$min, $max, $hardMax] = $isRed ? [18.0, 28.0, 32.0] : [10.0, 18.0, 22.0];
        if ($t > $hardMax) {
            return $this->alert('temperature', 'critical', $lot, "Temperature {$t}°C is too high.", 'Cool the vessel.');
        }
        if ($t < $min) {
            return $this->alert('temperature', 'medium', $lot, "Temperature {$t}°C is below the ideal range.", 'Warm the vessel.');
        }
        if ($t > $max) {
            return $this->alert('temperature', 'medium', $lot, "Temperature {$t}°C is above the ideal range.", 'Cool the vessel.');
        }

        return null;
    }

    /** @return Alert|null */
    private function volatileAcidityAlert(WineLot $lot, CellarAnalysis $latest): ?array
    {
        if ($latest->volatile_acidity === null) {
            return null;
        }
        $va = (float) $latest->volatile_acidity;
        if ($va > 0.8) {
            return $this->alert('volatile_acidity', 'critical', $lot, "Volatile acidity {$va} g/L is dangerously high.", 'Investigate spoilage immediately.');
        }
        if ($va > 0.6) {
            return $this->alert('volatile_acidity', 'high', $lot, "Volatile acidity {$va} g/L is elevated.", 'Check for acetobacter.');
        }

        return null;
    }

    /** @return Alert|null */
    private function phAlert(WineLot $lot, CellarAnalysis $latest): ?array
    {
        if ($latest->ph !== null && (float) $latest->ph > 3.8) {
            return $this->alert('high_ph', 'medium', $lot, "pH {$latest->ph} is high — spoilage risk.", 'Consider acidification.');
        }

        return null;
    }

    /** @return Alert|null */
    private function mlfAlert(WineLot $lot, CellarAnalysis $latest): ?array
    {
        if ($latest->malic !== null && (float) $latest->malic < 0.1 && $latest->lactic !== null && (float) $latest->lactic > 0) {
            return $this->alert('mlf_complete', 'info', $lot, 'Malolactic fermentation appears complete.', 'Add SO₂ to stabilise.');
        }

        return null;
    }

    /** @return Alert|null */
    private function completeAlert(WineLot $lot, CellarAnalysis $latest): ?array
    {
        if ($latest->brix !== null && (float) $latest->brix <= 2 && (float) $latest->brix >= -1) {
            return $this->alert('fermentation_complete', 'info', $lot, 'Sugar is depleted — fermentation looks complete.', 'Plan pressing/racking.');
        }

        return null;
    }

    /**
     * @return Alert
     */
    private function alert(string $type, string $severity, WineLot $lot, string $message, string $suggestion): array
    {
        return [
            'type' => $type,
            'severity' => $severity,
            'lot_id' => $lot->getKey(),
            'lot_name' => $lot->name,
            'message' => $message,
            'suggestion' => $suggestion,
        ];
    }
}
