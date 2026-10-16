<?php

declare(strict_types=1);

namespace App\Queries;

use App\Enums\CellarActivityStatus;
use App\Models\Bottling;
use App\Models\CellarActivity;
use App\Models\CellarAddition;
use App\Models\CellarAnalysis;
use App\Models\CellarProcess;
use App\Models\CellarTastingNote;
use App\Models\CellarTransfer;
use App\Models\VesselLot;
use App\Models\WineLot;

/**
 * A lot's journey, newest first: creation, wine placed in vessels, additions,
 * analyses, tastings, transfers in and out, processes (incl. losses, gains and
 * toppings), bottlings, and planned work done or skipped. Each event carries a
 * type, a date, a one-line title and the id of the record behind it.
 */
class LotTimelineQuery
{
    /**
     * @return list<array{type: string, date: string|null, title: string, detail: string|null, ref_id: string|null}>
     */
    public function get(WineLot $lot): array
    {
        $id = (string) $lot->getKey();
        $events = [];
        $add = function (string $type, mixed $date, string $title, ?string $detail = null, ?string $ref = null) use (&$events): void {
            $events[] = [
                'type' => $type,
                'date' => $date instanceof \DateTimeInterface ? $date->format(DATE_ATOM) : null,
                'title' => $title,
                'detail' => $detail,
                'ref_id' => $ref,
            ];
        };

        $num = fn (mixed $v): string => self::num($v);

        $add('created', $lot->created_at, 'Lot created — '.$num($lot->initial_volume).' L', $lot->grape_variety, $id);

        foreach (VesselLot::query()->with('vessel')->where('wine_lot_id', $id)->get() as $vl) {
            /** @var VesselLot $vl */
            $add('vessel', $vl->added_at ?? $vl->created_at, "In {$vl->vessel?->name}", $num($vl->volume).' L', (string) $vl->getKey());
        }

        foreach (CellarAddition::query()->with('vessel')->where('wine_lot_id', $id)->get() as $a) {
            /** @var CellarAddition $a */
            $where = $a->vessel !== null ? " → {$a->vessel->name}" : '';
            $add('addition', $a->created_at, $a->name.' '.$num($a->quantity)." {$a->unit}{$where}", $a->note, (string) $a->getKey());
        }

        foreach (CellarAnalysis::query()->where('wine_lot_id', $id)->get() as $an) {
            /** @var CellarAnalysis $an */
            $values = [];
            foreach (['free_so2' => 'free SO₂', 'total_so2' => 'total SO₂', 'ph' => 'pH', 'alcohol' => 'alc', 'brix' => 'Brix', 'volatile_acidity' => 'VA'] as $param => $label) {
                if ($an->getAttribute($param) !== null) {
                    $values[] = "{$label} ".$num($an->getAttribute($param));
                }
            }
            $add('analysis', $an->date, 'Analysis', implode(' · ', $values) ?: $an->note, (string) $an->getKey());
        }

        foreach (CellarTastingNote::query()->where('wine_lot_id', $id)->get() as $n) {
            /** @var CellarTastingNote $n */
            $add('tasting', $n->date, 'Tasting'.($n->score !== null ? " — {$n->score}/100" : ''), $n->overall ?? $n->note, (string) $n->getKey());
        }

        foreach (CellarTransfer::query()->with(['fromLot', 'toLot'])->where('from_lot_id', $id)->orWhere('to_lot_id', $id)->get() as $t) {
            /** @var CellarTransfer $t */
            $in = $t->to_lot_id === $id && $t->from_lot_id !== $id;
            $title = match (true) {
                $t->from_lot_id === $t->to_lot_id => 'Racked '.$num($t->volume_liters).' L',
                $in => $num($t->volume_liters)." L in from {$t->fromLot?->name}",
                default => $num($t->volume_liters)." L out to {$t->toLot?->name}",
            };
            $add($in ? 'transfer_in' : 'transfer', $t->created_at, $title, $t->note, (string) $t->getKey());
        }

        foreach (CellarProcess::query()->where('wine_lot_id', $id)->get() as $p) {
            /** @var CellarProcess $p */
            $add('process', $p->date, $p->kind.($p->volume !== null ? ' '.$num($p->volume).' L' : ''), $p->note, (string) $p->getKey());
        }

        foreach (Bottling::query()->with('inventoryItem')->where('wine_lot_id', $id)->get() as $b) {
            /** @var Bottling $b */
            $add('bottling', $b->date, "Bottled {$b->bottle_count} × {$b->bottle_volume_ml} ml", $b->inventoryItem?->name, (string) $b->getKey());
        }

        foreach (CellarActivity::query()->where('wine_lot_id', $id)->where('status', '!=', CellarActivityStatus::Planned->value)->get() as $act) {
            /** @var CellarActivity $act */
            $skipped = $act->status === CellarActivityStatus::Skipped;
            $add(
                $skipped ? 'planned_skipped' : 'planned_done',
                $skipped ? $act->skipped_at : $act->applied_at,
                ($skipped ? 'Skipped: ' : 'Done: ').$act->type->label(),
                $act->note,
                (string) $act->getKey(),
            );
        }

        usort($events, fn (array $a, array $b): int => strcmp((string) $b['date'], (string) $a['date']));

        return $events;
    }

    /** "2800.000" → "2800", "3.250" → "3.25" — numbers as people write them. */
    private static function num(mixed $value): string
    {
        $s = (string) $value;

        return str_contains($s, '.') ? rtrim(rtrim($s, '0'), '.') : $s;
    }
}
