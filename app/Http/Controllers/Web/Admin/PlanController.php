<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\Admin;

use App\Actions\Billing\CreatePlanAction;
use App\Actions\Billing\CreateStripePriceForPlanAction;
use App\Actions\Billing\DeletePlanAction;
use App\Actions\Billing\UpdatePlanAction;
use App\Enums\Module;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Plans\StorePlanRequest;
use App\Http\Requests\Admin\Plans\UpdatePlanRequest;
use App\Models\Plan;
use App\Queries\ListPlansQuery;
use App\Services\Billing\StripeGateway;
use App\Support\Money\Money;
use App\Support\PerPage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;
use Throwable;

/**
 * Port of App\Filament\Resources\Plans\**. Every read still goes through
 * ListPlansQuery, every write through the same Actions Filament used — only
 * the envelope changes.
 */
class PlanController extends Controller
{
    public function index(Request $request, ListPlansQuery $query): Response
    {
        $search = trim((string) $request->query('search', ''));

        $plans = $query->builder()
            ->when($search !== '', fn ($q) => $q->where(function ($q) use ($search): void {
                $q->where('name', 'like', "%{$search}%")->orWhere('slug', 'like', "%{$search}%");
            }))
            ->orderBy('name')
            ->paginate(PerPage::fromRequest($request), ['*'], 'page');

        return Inertia::render('Admin/Plans/Index', [
            'plans' => [
                'data' => array_map(fn (Plan $plan): array => $this->row($plan), $plans->items()),
                'meta' => [
                    'current_page' => $plans->currentPage(),
                    'last_page' => $plans->lastPage(),
                    'per_page' => $plans->perPage(),
                    'total' => $plans->total(),
                ],
            ],
            'filters' => ['search' => $search !== '' ? $search : null],
            'moduleOptions' => $this->moduleOptions(),
        ]);
    }

    public function show(Plan $plan): Response
    {
        $plan->loadCount('tenants');
        $plan->load(['tenants.subscription']);

        return Inertia::render('Admin/Plans/Show', [
            'plan' => $this->row($plan),
            'tenants' => $plan->tenants
                ->map(fn ($tenant): array => [
                    'id' => $tenant->getKey(),
                    'name' => $tenant->name,
                    'slug' => $tenant->slug,
                    'status' => $tenant->status->value,
                    'stripe_status' => $tenant->subscription?->stripe_status,
                ])
                ->values(),
            'moduleOptions' => $this->moduleOptions(),
            'canCreateStripePrice' => $plan->stripe_price_id === null
                && $plan->price_minor !== null
                && app(StripeGateway::class)->isConfigured(),
        ]);
    }

    public function store(StorePlanRequest $request, CreatePlanAction $action): RedirectResponse
    {
        $plan = $action->execute($this->normalizePrice($request->validated()));

        return redirect('/admin-new/plans/'.$plan->getKey())->with('success', __('Plan created.'));
    }

    public function update(UpdatePlanRequest $request, Plan $plan, UpdatePlanAction $action): RedirectResponse
    {
        $action->execute($plan, $this->normalizePrice($request->validated()));

        return back()->with('success', __('Plan updated.'));
    }

    public function destroy(Plan $plan, DeletePlanAction $action): RedirectResponse
    {
        $action->execute($plan);

        return redirect('/admin-new/plans')->with('success', __('Plan deleted.'));
    }

    /**
     * "Set price in Stripe" — port of Plans\Actions\CreateStripePriceAction,
     * calling the underlying Action directly rather than the Filament wrapper.
     */
    public function createStripePrice(
        Plan $plan,
        CreateStripePriceForPlanAction $action,
        StripeGateway $stripe,
    ): RedirectResponse {
        if ($plan->stripe_price_id !== null || $plan->price_minor === null || ! $stripe->isConfigured()) {
            abort(HttpResponse::HTTP_FORBIDDEN);
        }

        try {
            $action->execute($plan);
        } catch (Throwable $e) {
            return back()->with('error', __('Could not create the Stripe price: :message', ['message' => $e->getMessage()]));
        }

        return back()->with('success', __('Stripe price created.'));
    }

    /**
     * Converts the form's major-unit `price_minor` string (e.g. "15.00", same
     * field name Filament used) into the integer minor units CreatePlanAction/
     * UpdatePlanAction pass straight to the model's MoneyCast.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function normalizePrice(array $data): array
    {
        if (array_key_exists('price_minor', $data)) {
            $currency = is_string($data['currency'] ?? null) && $data['currency'] !== '' ? $data['currency'] : 'EUR';

            $data['price_minor'] = $data['price_minor'] === null || $data['price_minor'] === ''
                ? null
                : Money::fromMajor((string) $data['price_minor'], $currency)->getMinorAmount();
        }

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    private function row(Plan $plan): array
    {
        return [
            'id' => $plan->getKey(),
            'name' => $plan->name,
            'slug' => $plan->slug,
            'price_minor' => $plan->price_minor?->getMinorAmount(),
            'price_major' => $plan->price_minor?->toMajor(),
            'currency' => $plan->currency,
            'modules' => $plan->modules ?? [],
            'stripe_price_id' => $plan->stripe_price_id,
            'trial_days' => $plan->trial_days,
            'grace_full_days' => $plan->grace_full_days,
            'grace_readonly_days' => $plan->grace_readonly_days,
            'interval' => $plan->interval,
            'is_active' => $plan->is_active,
            'is_public' => $plan->is_public,
            'tenants_count' => $plan->tenants_count ?? 0,
        ];
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    private function moduleOptions(): array
    {
        return array_map(
            fn (Module $m): array => ['value' => $m->value, 'label' => $m->label()],
            Module::cases(),
        );
    }
}
