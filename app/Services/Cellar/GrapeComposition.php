<?php

declare(strict_types=1);

namespace App\Services\Cellar;

use App\Models\WineLot;
use App\Models\WineLotGrape;

/**
 * Writes a lot's grape rows and the lot fields derived from them, as the
 * prototype's lot form did: grape cost = Σ price/kg × kg, harvest weight =
 * Σ kg, €/kg = the weighted average, grape_variety = the varieties joined.
 *
 * Rows are matched by variety, so editing keeps what the form does not send —
 * a blend's `percentage` and a row's `harvest_entry_id` (the prototype deleted
 * and recreated the rows, losing both).
 */
class GrapeComposition
{
    /**
     * @param  list<array<string, mixed>>  $grapes  [{grape_variety, percentage?, price_per_kg?, weight_kg?}]
     */
    public function apply(WineLot $lot, array $grapes): void
    {
        $existing = $lot->grapes()->get()->keyBy(fn (WineLotGrape $g): string => mb_strtolower(trim($g->grape_variety)));
        $kept = [];

        foreach ($grapes as $row) {
            $variety = trim((string) $row['grape_variety']);
            $key = mb_strtolower($variety);
            $kept[$key] = true;

            $attributes = ['grape_variety' => $variety];
            foreach (['percentage', 'weight_kg'] as $field) {
                if (array_key_exists($field, $row)) {
                    $attributes[$field] = $row[$field];
                }
            }
            if (array_key_exists('price_per_kg', $row)) {
                $attributes['price_per_kg'] = $row['price_per_kg'] !== null ? (int) $row['price_per_kg'] : null;
            }

            $grape = $existing[$key] ?? null;
            if ($grape !== null) {
                $grape->fill($attributes)->save();
            } else {
                $lot->grapes()->create($attributes);
            }
        }

        foreach ($existing as $key => $grape) {
            if (! isset($kept[$key])) {
                $grape->delete();
            }
        }

        $this->deriveLotFields($lot);
    }

    private function deriveLotFields(WineLot $lot): void
    {
        $rows = $lot->grapes()->orderBy('created_at')->orderBy('id')->get();
        if ($rows->isEmpty()) {
            return;
        }

        $lot->grape_variety = $rows->pluck('grape_variety')->implode(', ');

        $priced = $rows->filter(fn (WineLotGrape $g): bool => $g->price_per_kg !== null && $g->weight_kg !== null);
        if ($priced->isNotEmpty()) {
            $cost = (int) round($priced->sum(fn (WineLotGrape $g): float => ($g->price_per_kg?->getMinorAmount() ?? 0) * (float) $g->weight_kg));
            $kg = $priced->sum(fn (WineLotGrape $g): float => (float) $g->weight_kg);
            $lot->fill([
                'grape_cost' => $cost,
                'harvest_weight_kg' => number_format($kg, 3, '.', ''),
                'grape_price_per_kg' => $kg > 0 ? (int) round($cost / $kg) : null,
            ]);
        }

        $lot->save();
    }

    /**
     * A lot's composition as variety → share (0–1): the rows' percentages when
     * set, else their weights, else equal shares.
     *
     * @return array<string, float>
     */
    public function shares(WineLot $lot): array
    {
        $rows = $lot->grapes()->get();
        if ($rows->isEmpty()) {
            return [trim($lot->grape_variety) !== '' ? trim($lot->grape_variety) : 'Unknown' => 1.0];
        }

        $basis = $rows->every(fn (WineLotGrape $g): bool => $g->percentage !== null) ? 'percentage'
            : ($rows->every(fn (WineLotGrape $g): bool => $g->weight_kg !== null && (float) $g->weight_kg > 0) ? 'weight_kg' : null);
        $sum = $basis !== null ? $rows->sum(fn (WineLotGrape $g): float => (float) $g->{$basis}) : (float) $rows->count();

        $out = [];
        foreach ($rows as $g) {
            $value = $basis !== null ? (float) $g->{$basis} : 1.0;
            $out[$g->grape_variety] = ($out[$g->grape_variety] ?? 0.0) + ($sum > 0 ? $value / $sum : 0.0);
        }

        return $out;
    }

    /**
     * After `$addedLiters` of `$from` were blended into `$into` (which held
     * `$intoLitersBefore`), rewrite `$into`'s grape percentages by volume.
     * Prices and weights of existing rows are kept. A negative `$addedLiters`
     * undoes a blend-in (varieties left with no share are dropped).
     */
    public function mix(WineLot $into, float $intoLitersBefore, WineLot $from, float $addedLiters): void
    {
        $total = $intoLitersBefore + $addedLiters;
        if ($total <= 0) {
            return;
        }

        $mixed = [];
        foreach ($this->shares($into) as $variety => $share) {
            $mixed[$variety] = ($mixed[$variety] ?? 0.0) + $share * $intoLitersBefore / $total;
        }
        foreach ($this->shares($from) as $variety => $share) {
            $mixed[$variety] = ($mixed[$variety] ?? 0.0) + $share * $addedLiters / $total;
        }

        $mixed = array_filter($mixed, fn (float $s): bool => $s > 0.00005);
        $existing = $into->grapes()->get()->keyBy(fn (WineLotGrape $g): string => mb_strtolower(trim($g->grape_variety)));
        $keep = array_flip(array_map(fn (string $v): string => mb_strtolower(trim($v)), array_keys($mixed)));
        foreach ($existing as $key => $row) {
            if (! isset($keep[$key])) {
                $row->delete();
            }
        }
        foreach ($mixed as $variety => $share) {
            $percentage = number_format($share * 100, 2, '.', '');
            $row = $existing[mb_strtolower(trim($variety))] ?? null;
            if ($row !== null) {
                $row->percentage = $percentage;
                $row->save();
            } else {
                $into->grapes()->create(['grape_variety' => $variety, 'percentage' => $percentage]);
            }
        }

        $into->grape_variety = implode(', ', array_keys($mixed));
        $into->save();
    }
}
