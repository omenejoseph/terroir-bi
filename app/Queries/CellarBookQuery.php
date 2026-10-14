<?php

declare(strict_types=1);

namespace App\Queries;

use App\Models\Bottling;
use App\Models\CellarAddition;
use App\Models\CellarAnalysis;
use App\Models\CellarProcess;
use App\Models\CellarTransfer;
use App\Models\User;
use App\Models\WineLot;
use Carbon\CarbonInterface;

/**
 * The cellar book (podrumska evidencija): every recorded cellar event in a
 * period, for every lot — harvest-linked or not, blends included — with
 * intake, additions, analyses, processes (incl. losses, gains, toppings),
 * transfers and bottlings. The prototype's book only covered lots with a
 * harvest entry and left out processes, analyses and losses.
 */
class CellarBookQuery
{
    /**
     * @return list<array{date: string, lot_number: string, lot_name: string, vintage: string, event: string, vessel: string|null, quantity: string|null, unit: string|null, detail: string|null, by: string|null}>
     */
    public function get(CarbonInterface $from, CarbonInterface $to): array
    {
        $rows = [];
        $users = User::query()->get()->mapWithKeys(fn (User $u): array => [(string) $u->getKey() => $u->fullName()])->all();
        $by = fn (?string $id): ?string => $id !== null ? ($users[$id] ?? null) : null;
        $push = function (mixed $date, WineLot $lot, string $event, ?string $vessel, mixed $qty, ?string $unit, ?string $detail, ?string $userId) use (&$rows, $by): void {
            $rows[] = [
                'date' => $date instanceof \DateTimeInterface ? $date->format('Y-m-d H:i') : '',
                'lot_number' => $lot->lot_number,
                'lot_name' => $lot->name,
                'vintage' => $lot->vintage,
                'event' => $event,
                'vessel' => $vessel,
                'quantity' => $qty !== null ? (string) $qty : null,
                'unit' => $unit,
                'detail' => $detail,
                'by' => $by($userId),
            ];
        };
        $range = [$from, $to];

        foreach (WineLot::query()->whereBetween('created_at', $range)->get() as $lot) {
            $push($lot->created_at, $lot, str_starts_with($lot->lot_number, 'BLEND-') ? 'Blend created' : 'Intake', null, $lot->initial_volume, 'L', $lot->grape_variety, null);
        }
        foreach (CellarAddition::query()->with(['wineLot', 'vessel'])->whereBetween('created_at', $range)->get() as $a) {
            if ($a->wineLot !== null) {
                $push($a->created_at, $a->wineLot, 'Addition', $a->vessel?->name, $a->quantity, $a->unit, trim($a->name.' '.($a->note ?? '')), $a->created_by_id);
            }
        }
        foreach (CellarAnalysis::query()->with(['wineLot', 'vessel'])->whereBetween('date', $range)->get() as $an) {
            if ($an->wineLot !== null) {
                $values = [];
                foreach (CellarAnalysis::PARAMETERS as $param) {
                    if ($an->getAttribute($param) !== null) {
                        $values[] = $param.'='.$an->getAttribute($param);
                    }
                }
                $push($an->date, $an->wineLot, 'Analysis', $an->vessel?->name, null, null, implode(', ', $values), $an->created_by_id);
            }
        }
        foreach (CellarProcess::query()->with(['wineLot', 'vessel'])->whereBetween('date', $range)->get() as $p) {
            if ($p->wineLot !== null) {
                $push($p->date, $p->wineLot, $p->kind, $p->vessel?->name, $p->volume, $p->volume !== null ? 'L' : null, $p->note, $p->created_by_id);
            }
        }
        foreach (CellarTransfer::query()->with(['fromLot', 'toLot', 'fromVessel', 'toVessel'])->whereBetween('created_at', $range)->get() as $t) {
            if ($t->fromLot !== null) {
                $detail = trim(($t->fromVessel->name ?? '?').' → '.($t->toVessel->name ?? '?').($t->to_lot_id !== $t->from_lot_id ? ' into '.$t->toLot?->lot_number : ''));
                $push($t->created_at, $t->fromLot, 'Transfer ('.$t->type->value.')', $t->fromVessel?->name, $t->volume_liters, 'L', $detail, $t->created_by_id);
            }
        }
        foreach (Bottling::query()->with(['wineLot', 'inventoryItem'])->whereBetween('date', $range)->get() as $b) {
            if ($b->wineLot !== null) {
                $push($b->date, $b->wineLot, 'Bottling', null, $b->volume_used, 'L', "{$b->bottle_count} × {$b->bottle_volume_ml} ml".($b->inventoryItem !== null ? ' → '.$b->inventoryItem->name : ''), $b->created_by_id);
            }
        }

        usort($rows, fn (array $a, array $b): int => [$a['date'], $a['lot_number']] <=> [$b['date'], $b['lot_number']]);

        return $rows;
    }
}
