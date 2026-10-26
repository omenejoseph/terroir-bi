<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\Hospitality;

use App\Actions\Hospitality\RemoveAgencyPriceAction;
use App\Actions\Hospitality\SetAgencyPriceAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Hospitality\SetAgencyPriceRequest;
use App\Models\Customer;
use App\Models\HospitalityAgencyPrice;
use App\Models\HospitalityProgram;
use App\Services\Hospitality\ReservationPricing;
use Illuminate\Http\RedirectResponse;

/**
 * The agency price book, one (program, agency) row at a time. A price given as a rebate is worked
 * out from the program's base price here, through ReservationPricing's rounding, and stored with
 * the rebate so a later base-price change carries it along.
 */
class AgencyPriceController extends Controller
{
    public function update(
        SetAgencyPriceRequest $request,
        HospitalityProgram $program,
        Customer $customer,
        SetAgencyPriceAction $action,
        ReservationPricing $pricing,
    ): RedirectResponse {
        // Only agencies have a price book; the pickers never offer anyone else.
        abort_unless((bool) $customer->is_agency, 422, __('Only agencies can have agency prices.'));

        $rebate = $request->validated('rebate_percent');

        if ($rebate !== null && (float) $rebate > 0) {
            $action->execute($program, $customer, $pricing->afterRebate($program->base_price->getMinorAmount(), (float) $rebate), (float) $rebate);
        } else {
            $action->execute($program, $customer, (int) $request->validated('price_per_pax'));
        }

        return back()->with('success', __('Agency price saved.'));
    }

    public function destroy(HospitalityProgram $program, Customer $customer, RemoveAgencyPriceAction $action): RedirectResponse
    {
        $price = HospitalityAgencyPrice::query()
            ->where('program_id', $program->getKey())
            ->where('customer_id', $customer->getKey())
            ->firstOrFail();

        $action->execute($price);

        return back()->with('success', __('Agency price removed.'));
    }
}
