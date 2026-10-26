<?php

declare(strict_types=1);

namespace App\Actions\Hospitality;

use App\Models\HospitalityProgram;

class CreateProgramAction
{
    /**
     * @param  array{name: string, description?: ?string, duration_mins?: ?int, base_price: int, max_pax?: ?int, is_active?: bool}  $data
     */
    public function execute(array $data): HospitalityProgram
    {
        // New programs go to the end of the list, as the other ordered lists do.
        $data['sort_order'] = (int) HospitalityProgram::query()->max('sort_order') + 1;

        return HospitalityProgram::create($data);
    }
}
