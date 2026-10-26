<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\Hospitality;

use App\Actions\Hospitality\AddReservationNoteAction;
use App\Actions\Hospitality\ChangeReservationStatusAction;
use App\Actions\Hospitality\CreateReservationAction;
use App\Actions\Hospitality\DeleteReservationAction;
use App\Actions\Hospitality\UpdateReservationAction;
use App\Enums\ReservationStatus;
use App\Exceptions\ReservationNotDeletableException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Hospitality\AddReservationNoteRequest;
use App\Http\Requests\Hospitality\ChangeReservationStatusRequest;
use App\Http\Requests\Hospitality\QuoteReservationRequest;
use App\Http\Requests\Hospitality\StoreReservationRequest;
use App\Http\Requests\Hospitality\UpdateReservationRequest;
use App\Models\Customer;
use App\Models\HospitalityAgencyPrice;
use App\Models\HospitalityProgram;
use App\Models\Reservation;
use App\Models\User;
use App\Queries\HospitalityQuickStatsQuery;
use App\Queries\ReservationListQuery;
use App\Services\Hospitality\HospitalityOptions;
use App\Services\Hospitality\ReservationPresenter;
use App\Services\Hospitality\ReservationPricing;
use App\Support\PerPage;
use App\Support\ReservationFilters;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Hospitality (the old app's "Agencies", /dashboard/hospitality): the reservation list with its
 * tiles and filters, the Programs tab, and a reservation's own page. Thin over the
 * Actions/Queries in docs/21 §2.4; pricing is only ever decided by ReservationPricing.
 */
class ReservationController extends Controller
{
    /** @var list<string> */
    private const TABS = ['reservations', 'programs'];

    public function index(
        Request $request,
        ReservationListQuery $list,
        HospitalityQuickStatsQuery $stats,
        ReservationPresenter $presenter,
        HospitalityOptions $options,
    ): Response {
        $filters = ReservationFilters::fromRequest($request);
        $tab = in_array($request->query('tab'), self::TABS, true) ? (string) $request->query('tab') : 'reservations';

        return Inertia::render('Hospitality/Index', [
            'tab' => $tab,
            'filters' => $filters,
            // Closures, so a filter or page change re-evaluates only what it names.
            'reservations' => fn (): array => $presenter->page($list->paginate($filters, PerPage::fromRequest($request))),
            'stats' => fn (): array => $stats->get(),
            'statuses' => ReservationPresenter::statuses(),
            // The filters' pickers and the Programs tab both list every program, retired ones included.
            'programs' => fn (): array => $options->programs(),
            'agencies' => fn (): array => $options->agencies(),
            // A program's agency prices, only fetched while its drawer is open (?program=…).
            'programPrices' => Inertia::optional(fn (): array => $options->agencyPrices((string) $request->query('program'))),
        ]);
    }

    public function show(Reservation $reservation, ReservationPresenter $presenter, HospitalityOptions $options): Response
    {
        return Inertia::render('Hospitality/Reservation', [
            'reservation' => fn (): array => $presenter->detail($reservation),
            'statuses' => ReservationPresenter::statuses(),
            // The edit drawer's pickers, loaded when it opens.
            'programs' => Inertia::optional(fn (): array => $options->programs()),
            'agencies' => Inertia::optional(fn (): array => $options->agencies()),
        ]);
    }

    /** The form's live price: what this group would pay, decided by the one pricing rule. */
    public function quote(QuoteReservationRequest $request, ReservationPricing $pricing): JsonResponse
    {
        $program = HospitalityProgram::query()->findOrFail((string) $request->validated('program_id'));
        $customerId = $request->validated('customer_id');
        $customer = is_string($customerId) ? Customer::query()->findOrFail($customerId) : null;
        $pax = (int) $request->validated('pax');

        $price = $pricing->pricePerPax($program, $customer);
        $base = $program->base_price->getMinorAmount();
        $currency = $program->base_price->getCurrencyCode();

        return response()->json([
            'base_price' => ['minor' => $base, 'currency' => $currency],
            'price_per_pax' => ['minor' => $price, 'currency' => $currency],
            'total' => ['minor' => $pricing->total($price, $pax), 'currency' => $currency],
            'rebate_percent' => $base > 0 ? round((1 - $price / $base) * 100, 1) : null,
            // Where the price came from, so the form can say "agency price" rather than leave a bare number.
            'source' => match (true) {
                $customer !== null && HospitalityAgencyPrice::query()
                    ->where('program_id', $program->getKey())
                    ->where('customer_id', $customer->getKey())
                    ->exists() => 'agency_price',
                $price !== $base => 'customer_rebate',
                default => 'base_price',
            },
            'max_pax' => $program->max_pax,
        ]);
    }

    public function store(StoreReservationRequest $request, CreateReservationAction $action): RedirectResponse
    {
        /** @var array{date: string, pax: int, program_id: string, customer_id?: ?string, guest_name?: ?string, guest_email?: ?string, guest_phone?: ?string, allergies?: ?string, notes?: ?string} $data */
        $data = $request->validated();
        $reservation = $action->execute($data, $this->userId($request));

        return redirect('/hospitality/reservations/'.$reservation->getKey())->with('success', __('Reservation created.'));
    }

    public function update(UpdateReservationRequest $request, Reservation $reservation, UpdateReservationAction $action): RedirectResponse
    {
        $action->execute($reservation, $request->validated());

        return back()->with('success', __('Reservation updated.'));
    }

    public function changeStatus(ChangeReservationStatusRequest $request, Reservation $reservation, ChangeReservationStatusAction $action): RedirectResponse
    {
        $note = $request->validated('note');
        $action->execute(
            $reservation,
            ReservationStatus::from((string) $request->validated('status')),
            is_string($note) && trim($note) !== '' ? trim($note) : null,
            $this->userId($request),
        );

        return back()->with('success', __('Status updated.'));
    }

    public function addNote(AddReservationNoteRequest $request, Reservation $reservation, AddReservationNoteAction $action): RedirectResponse
    {
        $action->execute($reservation, trim((string) $request->validated('note')), $this->userId($request));

        return back()->with('success', __('Note added.'));
    }

    public function destroy(Reservation $reservation, DeleteReservationAction $action): RedirectResponse
    {
        try {
            $action->execute($reservation);
        } catch (ReservationNotDeletableException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect('/hospitality')->with('success', __('Reservation deleted.'));
    }

    private function userId(Request $request): string
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return $user->getKey();
    }
}
