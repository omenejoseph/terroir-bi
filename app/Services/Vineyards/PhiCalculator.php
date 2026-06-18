<?php

declare(strict_types=1);

namespace App\Services\Vineyards;

use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;

/** Pre-harvest interval: the earliest safe harvest date after an application. */
class PhiCalculator
{
    public function endDate(?string $appliedAt, ?int $phiDays): ?string
    {
        if ($appliedAt === null || $phiDays === null) {
            return null;
        }

        return CarbonImmutable::parse($appliedAt)->addDays($phiDays)->toDateString();
    }

    public function endDateFrom(Carbon $appliedAt, int $phiDays): string
    {
        return $appliedAt->copy()->addDays($phiDays)->toDateString();
    }
}
