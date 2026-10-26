<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\Accommodation;

use App\Actions\Accommodation\SaveVillaAction;
use App\Actions\Accommodation\SaveVillaBookingAction;
use App\Actions\Accommodation\SaveVillaMenuItemAction;
use App\Enums\VillaBookingStatus;
use App\Enums\VillaOrderStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Accommodation\VillaBookingQuoteRequest;
use App\Http\Requests\Accommodation\VillaBookingRequest;
use App\Http\Requests\Accommodation\VillaMenuItemRequest;
use App\Http\Requests\Accommodation\VillaRateRequest;
use App\Http\Requests\Accommodation\VillaRequest;
use App\Http\Requests\Accommodation\VillaSectionRequest;
use App\Models\Villa;
use App\Models\VillaBooking;
use App\Models\VillaMenuItem;
use App\Models\VillaOrder;
use App\Models\VillaRate;
use App\Models\VillaSection;
use App\Queries\AccommodationOverviewQuery;
use App\Services\Accommodation\AccommodationPresenter;
use App\Services\Accommodation\BookingPricing;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Accommodation back office (the old app's /dashboard/accommodation, docs/21 §4 — 3a): overview,
 * bookings, villas with their portal content and rates, the in-villa menu, and guest orders.
 * ADMIN and HOSPITALITY. The guest portal itself (3b) is not built yet.
 */
class AccommodationController extends Controller
{
    /** @var list<string> */
    private const TABS = ['overview', 'bookings', 'villas', 'menu', 'orders'];

    public function index(Request $request, AccommodationPresenter $presenter, AccommodationOverviewQuery $overview): Response
    {
        $tab = in_array($request->query('tab'), self::TABS, true) ? (string) $request->query('tab') : 'overview';
        $filters = [
            'villa_id' => $this->str($request->query('villa_id')),
            'status' => $this->str($request->query('status')),
            'search' => $this->str($request->query('search')),
        ];

        return Inertia::render('Accommodation/Index', [
            'tab' => $tab,
            'options' => AccommodationPresenter::options(),
            'villas' => fn (): array => array_values(Villa::query()->orderByDesc('is_active')->orderBy('name')->get()
                ->map(fn (Villa $v): array => $presenter->villa($v))->all()),
            'overview' => fn (): array => $overview->get(),
            'filters' => $filters,
            'bookings' => fn (): array => array_values(VillaBooking::query()
                ->with('villa')
                ->when($filters['villa_id'] !== null, fn ($q) => $q->where('villa_id', $filters['villa_id']))
                ->when($filters['status'] !== null, fn ($q) => $q->where('status', $filters['status']))
                ->when($filters['search'] !== null, fn ($q) => $q->where(fn ($w) => $w
                    ->whereLike('guest_name', '%'.$filters['search'].'%')
                    ->orWhereLike('guest_email', '%'.$filters['search'].'%')))
                ->orderByDesc('check_in')
                ->limit(300)
                ->get()
                ->map(fn (VillaBooking $b): array => $presenter->booking($b))
                ->all()),
            // One booking with its orders, while its drawer is open (?booking=…).
            'booking' => Inertia::optional(function () use ($request, $presenter): ?array {
                $booking = VillaBooking::query()->with(['villa', 'orders.items', 'orders.booking.villa'])->find((string) $request->query('booking'));

                return $booking instanceof VillaBooking ? $presenter->booking($booking, withOrders: true) : null;
            }),
            'menu' => fn (): array => array_values(VillaMenuItem::query()->with('villa')
                ->orderBy('villa_id')->orderBy('category')->orderBy('sort_order')->get()
                ->map(fn (VillaMenuItem $i): array => $presenter->menuItem($i))->all()),
            'orders' => fn (): array => array_values(VillaOrder::query()->with(['items', 'booking.villa'])
                ->orderByDesc('created_at')->limit(200)->get()
                ->map(fn (VillaOrder $o): array => $presenter->order($o))->all()),
        ]);
    }

    public function showVilla(Villa $villa, AccommodationPresenter $presenter): Response
    {
        $villa->load(['rates', 'sections']);

        return Inertia::render('Accommodation/Villa', [
            'villa' => $presenter->villa($villa, full: true),
        ]);
    }

    public function storeVilla(VillaRequest $request, SaveVillaAction $action): RedirectResponse
    {
        $villa = $action->execute($request->validated());

        return redirect('/accommodation/villas/'.$villa->getKey())->with('success', __('Villa added.'));
    }

    public function updateVilla(VillaRequest $request, Villa $villa, SaveVillaAction $action): RedirectResponse
    {
        $action->execute($request->validated(), $villa);

        return back()->with('success', __('Villa updated.'));
    }

    public function storeRate(VillaRateRequest $request, Villa $villa): RedirectResponse
    {
        $villa->rates()->create($request->validated());

        return back()->with('success', __('Rate added.'));
    }

    public function updateRate(VillaRateRequest $request, Villa $villa, VillaRate $rate): RedirectResponse
    {
        abort_unless($rate->villa_id === $villa->getKey(), 404);
        $rate->update($request->validated());

        return back()->with('success', __('Rate updated.'));
    }

    public function destroyRate(Villa $villa, VillaRate $rate): RedirectResponse
    {
        abort_unless($rate->villa_id === $villa->getKey(), 404);
        $rate->delete();

        return back()->with('success', __('Rate removed.'));
    }

    public function storeSection(VillaSectionRequest $request, Villa $villa): RedirectResponse
    {
        $villa->sections()->create($request->validated() + [
            'sort_order' => (int) $villa->sections()->max('sort_order') + 1,
        ]);

        return back()->with('success', __('Section added.'));
    }

    public function updateSection(VillaSectionRequest $request, Villa $villa, VillaSection $section): RedirectResponse
    {
        abort_unless($section->villa_id === $villa->getKey(), 404);
        $section->update($request->validated());

        return back()->with('success', __('Section updated.'));
    }

    public function destroySection(Villa $villa, VillaSection $section): RedirectResponse
    {
        abort_unless($section->villa_id === $villa->getKey(), 404);
        $section->delete();

        return back()->with('success', __('Section removed.'));
    }

    /** The order of the portal's sections: each section's position is its index in `ids`. */
    public function reorderSections(Request $request, Villa $villa): RedirectResponse
    {
        $ids = $request->validate(['ids' => ['required', 'array'], 'ids.*' => ['string']])['ids'];

        DB::transaction(function () use ($villa, $ids): void {
            foreach (array_values($ids) as $position => $id) {
                $villa->sections()->whereKey($id)->update(['sort_order' => $position]);
            }
        });

        return back();
    }

    /** The booking form's live price, from the one pricing rule. */
    public function quote(VillaBookingQuoteRequest $request, BookingPricing $pricing): JsonResponse
    {
        $villa = Villa::query()->findOrFail((string) $request->validated('villa_id'));

        return response()->json($pricing->price(
            $villa,
            (string) $request->validated('check_in'),
            (string) $request->validated('check_out'),
            (string) ($request->validated('rebate_percent') ?? 0),
        ));
    }

    public function storeBooking(VillaBookingRequest $request, SaveVillaBookingAction $action): RedirectResponse
    {
        $action->execute($request->validated());

        return back()->with('success', __('Booking added.'));
    }

    public function updateBooking(VillaBookingRequest $request, VillaBooking $booking, SaveVillaBookingAction $action): RedirectResponse
    {
        $action->execute($request->validated(), $booking);

        return back()->with('success', __('Booking updated.'));
    }

    public function bookingStatus(Request $request, VillaBooking $booking): RedirectResponse
    {
        $status = $request->validate(['status' => ['required', Rule::enum(VillaBookingStatus::class)]])['status'];
        $booking->update(['status' => $status]);

        return back();
    }

    /** A booking is removed outright, its guest orders with it (as in the old app). */
    public function destroyBooking(VillaBooking $booking): RedirectResponse
    {
        $booking->delete();

        return redirect('/accommodation?tab=bookings')->with('success', __('Booking deleted.'));
    }

    public function storeMenuItem(VillaMenuItemRequest $request, SaveVillaMenuItemAction $action): RedirectResponse
    {
        $data = $request->validated();
        $every = (bool) ($data['every_villa'] ?? false);
        unset($data['every_villa']);
        $created = $action->execute($data, null, $every);

        return back()->with('success', trans_choice('{1} Menu item added.|[2,*] Menu item added to :count villas.', count($created), ['count' => count($created)]));
    }

    public function updateMenuItem(VillaMenuItemRequest $request, VillaMenuItem $menuItem, SaveVillaMenuItemAction $action): RedirectResponse
    {
        $data = $request->validated();
        unset($data['every_villa']);
        $action->execute($data, $menuItem);

        return back()->with('success', __('Menu item updated.'));
    }

    public function destroyMenuItem(VillaMenuItem $menuItem): RedirectResponse
    {
        $menuItem->delete();

        return back()->with('success', __('Menu item removed.'));
    }

    /** An order's status, and/or whether it has been paid (marking it paid stamps the time). */
    public function updateOrder(Request $request, VillaOrder $order): RedirectResponse
    {
        $data = $request->validate([
            'status' => ['sometimes', Rule::enum(VillaOrderStatus::class)],
            'paid' => ['sometimes', 'boolean'],
        ]);

        if (isset($data['status'])) {
            $order->status = VillaOrderStatus::from((string) $data['status']);
        }
        if (array_key_exists('paid', $data)) {
            $order->paid_at = $data['paid'] ? ($order->paid_at ?? now()) : null;
        }
        $order->save();

        return back();
    }

    private function str(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
