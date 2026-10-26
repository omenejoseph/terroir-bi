<?php

declare(strict_types=1);

namespace App\Services\Accommodation;

use App\Models\Villa;
use App\Models\VillaRate;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * The one place a stay is priced (docs/21 §4 — the old app had two diverging copies).
 *
 * Every night from check-in up to, not including, check-out is charged at the rate whose span
 * covers it, both ends of the span inclusive; where spans overlap the one starting latest wins. A
 * night no rate covers costs nothing and is reported, so the form can warn about it. The rebate is
 * a percentage (clamped to 0–100) of the subtotal, rounded half up to the minor unit. Dates are plain
 * calendar days — no time zone is involved.
 */
class BookingPricing
{
    /**
     * @return array{nights: int, subtotal: int, rebate: int, total: int, missing_nights: list<string>, breakdown: list<array{date: string, rate: ?string, amount: int}>}
     */
    public function price(Villa $villa, string $checkIn, string $checkOut, float|string $rebatePercent = 0): array
    {
        $from = CarbonImmutable::parse($checkIn)->startOfDay();
        $to = CarbonImmutable::parse($checkOut)->startOfDay();

        /** @var Collection<int, VillaRate> $rates */
        $rates = $villa->rates()->get()->sortByDesc(fn (VillaRate $r): string => $r->start_date->toDateString())->values();

        $subtotal = 0;
        $missing = [];
        $breakdown = [];

        for ($night = $from; $night->lessThan($to); $night = $night->addDay()) {
            $day = $night->toDateString();
            $rate = $rates->first(fn (VillaRate $r): bool => $r->start_date->toDateString() <= $day && $r->end_date->toDateString() >= $day);

            if (! $rate instanceof VillaRate) {
                $missing[] = $day;
                $breakdown[] = ['date' => $day, 'rate' => null, 'amount' => 0];

                continue;
            }

            $amount = $rate->nightly_rate->getMinorAmount();
            $subtotal += $amount;
            $breakdown[] = ['date' => $day, 'rate' => $rate->label, 'amount' => $amount];
        }

        $pct = max(0.0, min(100.0, (float) $rebatePercent));
        $rebate = intdiv($subtotal * (int) round($pct * 100) + 5_000, 10_000);

        return [
            'nights' => count($breakdown),
            'subtotal' => $subtotal,
            'rebate' => $rebate,
            'total' => $subtotal - $rebate,
            'missing_nights' => $missing,
            'breakdown' => $breakdown,
        ];
    }
}
