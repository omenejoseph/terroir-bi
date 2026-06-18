<?php

declare(strict_types=1);

namespace App\Services\Cellar;

use App\Models\WineLot;

/**
 * Generates the next tenant-scoped wine-lot number (LOT-YYYY-NNN). The WineLot
 * query is tenant-scoped, so the sequence restarts per tenant and per year. Call
 * inside the lot-creation transaction; the unique (tenant_id, lot_number) index
 * is the backstop against a race.
 */
class LotNumberGenerator
{
    public function next(?int $year = null): string
    {
        $year ??= (int) date('Y');
        $prefix = "LOT-{$year}-";

        $last = WineLot::query()
            ->where('lot_number', 'like', $prefix.'%')
            ->orderByDesc('lot_number')
            ->value('lot_number');

        $seq = is_string($last) ? ((int) substr($last, strlen($prefix))) + 1 : 1;

        return $prefix.str_pad((string) $seq, 3, '0', STR_PAD_LEFT);
    }
}
