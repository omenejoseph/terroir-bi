<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\Hospitality;

use App\Actions\Hospitality\CreateProgramAction;
use App\Actions\Hospitality\UpdateProgramAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Hospitality\ProgramRequest;
use App\Models\HospitalityProgram;
use Illuminate\Http\RedirectResponse;

/**
 * The Programs tab. A program is never deleted — retiring it (is_active=false) keeps its
 * reservations and price book intact and only takes it off the reservation form.
 */
class ProgramController extends Controller
{
    public function store(ProgramRequest $request, CreateProgramAction $action): RedirectResponse
    {
        /** @var array{name: string, description?: ?string, duration_mins?: ?int, base_price: int, max_pax?: ?int, is_active?: bool} $data */
        $data = $request->validated();
        $action->execute($data);

        return back()->with('success', __('Program added.'));
    }

    public function update(ProgramRequest $request, HospitalityProgram $program, UpdateProgramAction $action): RedirectResponse
    {
        $action->execute($program, $request->validated());

        return back()->with('success', __('Program updated.'));
    }
}
