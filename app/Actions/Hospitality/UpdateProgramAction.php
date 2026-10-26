<?php

declare(strict_types=1);

namespace App\Actions\Hospitality;

use App\Models\HospitalityProgram;
use App\Services\Hospitality\ReservationPricing;
use App\Support\Money\Money;
use Illuminate\Support\Facades\DB;

class UpdateProgramAction
{
    public function __construct(private readonly ReservationPricing $pricing) {}

    /**
     * Changing the base price re-derives every agency price that was set as a rebate off it, so
     * "10 % off" stays 10 % off. Prices set as a flat figure (no rebate) are left alone, and
     * reservations already taken keep the price they were taken at.
     *
     * @param  array<string, mixed>  $data
     */
    public function execute(HospitalityProgram $program, array $data): HospitalityProgram
    {
        return DB::transaction(function () use ($program, $data): HospitalityProgram {
            $program->fill($data);
            $baseChanged = $program->isDirty('base_price');
            $program->save();

            if ($baseChanged) {
                $base = $program->base_price;

                $program->agencyPrices()->where('rebate_percent', '>', 0)->get()->each(function ($price) use ($base): void {
                    if ($price->rebate_percent === null) {
                        return;
                    }

                    $price->price_per_pax = Money::fromMinor(
                        $this->pricing->afterRebate($base->getMinorAmount(), $price->rebate_percent),
                        $base->getCurrency(),
                    );
                    $price->save();
                });
            }

            return $program;
        });
    }
}
