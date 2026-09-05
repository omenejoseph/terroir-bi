<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\Admin;

use App\Actions\Billing\CreateBillingCheckoutLinkAction;
use App\Actions\Billing\SendBillingSetupLinkAction;
use App\Actions\Tenancy\AssignPlanToTenantAction;
use App\Actions\Tenancy\CreateTenantAction;
use App\Actions\Tenancy\UpdateTenantStatusAction;
use App\Enums\TenantRole;
use App\Enums\TenantStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Tenants\AssignPlanRequest;
use App\Http\Requests\Admin\Tenants\StoreTenantRequest;
use App\Http\Requests\Admin\Tenants\UpdateTenantStatusRequest;
use App\Models\Membership;
use App\Models\Tenant;
use App\Queries\ListPlansQuery;
use App\Queries\ListTenantsForAdminQuery;
use App\Services\Billing\SubscriptionAccessService;
use App\Support\PerPage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

/**
 * Port of App\Filament\Resources\Tenants\**. Every read still goes through
 * ListTenantsForAdminQuery / SubscriptionAccessService, every write through the
 * same Actions Filament used.
 */
class TenantController extends Controller
{
    public function index(Request $request, ListTenantsForAdminQuery $query, SubscriptionAccessService $access): Response
    {
        $search = trim((string) $request->query('search', ''));

        $tenants = $query->builder()
            ->when($search !== '', fn ($q) => $q->where('name', 'like', "%{$search}%"))
            ->orderBy('name')
            ->paginate(PerPage::fromRequest($request), ['*'], 'page');

        return Inertia::render('Admin/Tenants/Index', [
            'tenants' => [
                'data' => array_map(
                    fn (Tenant $tenant): array => $this->row($tenant, $access),
                    $tenants->items(),
                ),
                'meta' => [
                    'current_page' => $tenants->currentPage(),
                    'last_page' => $tenants->lastPage(),
                    'per_page' => $tenants->perPage(),
                    'total' => $tenants->total(),
                ],
            ],
            'filters' => ['search' => $search !== '' ? $search : null],
            'statusOptions' => array_map(fn (TenantStatus $s) => ['value' => $s->value, 'label' => $s->name], TenantStatus::cases()),
            'planOptions' => $this->planOptions(),
            'currencyOptions' => $this->currencyOptions(),
            'localeOptions' => $this->localeOptions(),
        ]);
    }

    public function show(Tenant $tenant, SubscriptionAccessService $access): Response
    {
        $tenant->load(['plan', 'subscription', 'memberships.user']);

        return Inertia::render('Admin/Tenants/Show', [
            'tenant' => $this->row($tenant, $access),
            'members' => $tenant->memberships
                ->map(fn (Membership $membership): array => [
                    'id' => $membership->getKey(),
                    'name' => $membership->user?->fullName() ?? '—',
                    'email' => $membership->user?->email,
                    'roles' => $membership->roles->map(fn ($role) => $role->value)->all(),
                    'status' => $membership->status->value,
                ])
                ->values(),
            'statusOptions' => array_map(fn (TenantStatus $s) => ['value' => $s->value, 'label' => $s->name], TenantStatus::cases()),
            'planOptions' => $this->planOptions(),
            'roleOptions' => $this->roleOptions(),
        ]);
    }

    public function store(StoreTenantRequest $request, CreateTenantAction $action): RedirectResponse
    {
        $data = $request->validated();

        $result = $action->execute([
            'name' => $data['name'],
            'slug' => $data['slug'],
            'currency' => $data['currency'],
            'locale' => $data['locale'],
            'plan_id' => $data['plan_id'] ?? null,
            'admin' => [
                'first_name' => $data['admin_first_name'],
                'last_name' => $data['admin_last_name'],
                'email' => $data['admin_email'],
                'password' => $data['admin_password'],
            ],
        ]);

        return redirect('/admin-new/tenants/'.$result['tenant']->getKey())->with('success', __('Tenant created.'));
    }

    public function updateStatus(UpdateTenantStatusRequest $request, Tenant $tenant, UpdateTenantStatusAction $action): RedirectResponse
    {
        $action->execute($tenant, TenantStatus::from($request->validated('status')));

        return back()->with('success', __('Tenant status updated.'));
    }

    public function assignPlan(AssignPlanRequest $request, Tenant $tenant, AssignPlanToTenantAction $action): RedirectResponse
    {
        $action->execute($tenant, $request->validated('plan_id'));

        return back()->with('success', __('Tenant plan updated.'));
    }

    /**
     * Generates (does not persist as a page prop) a Stripe Checkout link — a
     * plain JSON endpoint the "Generate subscription link" dialog fetches on
     * open, same as Filament's fillForm() did when its modal opened.
     */
    public function generateOnboardingLink(Tenant $tenant, CreateBillingCheckoutLinkAction $action): JsonResponse
    {
        try {
            return response()->json(['url' => $action->execute($tenant)]);
        } catch (Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function emailBillingLink(Tenant $tenant, SendBillingSetupLinkAction $action): RedirectResponse
    {
        try {
            $action->execute($tenant);
        } catch (Throwable $e) {
            return back()->with('error', __('Could not send the billing link: :message', ['message' => $e->getMessage()]));
        }

        return back()->with('success', __('Billing link sent.'));
    }

    /**
     * @return array<string, mixed>
     */
    private function row(Tenant $tenant, SubscriptionAccessService $access): array
    {
        $computed = $access->compute($tenant, $tenant->subscription, $tenant->plan, Carbon::now());
        $plan = $tenant->plan;

        return [
            'id' => $tenant->getKey(),
            'name' => $tenant->name,
            'slug' => $tenant->slug,
            'status' => $tenant->status->value,
            'plan' => $plan === null ? null : [
                'id' => $plan->getKey(),
                'name' => $plan->name,
                'price_label' => $plan->price_minor !== null
                    ? $plan->price_minor->toMajor().' '.$plan->currency.' / '.$plan->interval
                    : null,
                'modules' => array_map(fn ($m) => $m->label(), $plan->modules()),
            ],
            'plan_id' => $tenant->plan_id,
            'access' => $computed->level->value,
            'subscription' => $tenant->subscription === null ? null : [
                'stripe_status' => $tenant->subscription->stripe_status,
                'stripe_customer_id' => $tenant->subscription->stripe_customer_id,
                'stripe_subscription_id' => $tenant->subscription->stripe_subscription_id,
                'stripe_price_id' => $tenant->subscription->stripe_price_id,
                'trial_ends_at' => $tenant->subscription->trial_ends_at?->toIso8601String(),
                'current_period_end' => $tenant->subscription->current_period_end?->toIso8601String(),
                'canceled_at' => $tenant->subscription->canceled_at?->toIso8601String(),
            ],
            // Who "Generate/Email subscription link" is for: a paid plan with
            // no Stripe subscription yet — same rule as Filament's
            // TenantBillingActions::needsSubscription().
            'needs_subscription' => $plan?->stripe_price_id !== null
                && $tenant->subscription?->stripe_subscription_id === null,
        ];
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    private function planOptions(): array
    {
        return collect(app(ListPlansQuery::class)->options())
            ->map(fn (string $name, string $id): array => ['value' => $id, 'label' => $name])
            ->values()
            ->all();
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    private function roleOptions(): array
    {
        return array_map(
            fn (TenantRole $role): array => ['value' => $role->value, 'label' => $role->label()],
            TenantRole::cases(),
        );
    }

    /**
     * Supported currencies (code → "EUR — Euro") — port of TenantForm's
     * private currencyOptions().
     *
     * @return list<array{value: string, label: string}>
     */
    private function currencyOptions(): array
    {
        $options = [];
        foreach ((array) config('money.currencies', []) as $code => $meta) {
            $name = is_array($meta) && isset($meta['name']) ? (string) $meta['name'] : (string) $code;
            $options[] = ['value' => (string) $code, 'label' => $code.' — '.$name];
        }

        return $options;
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    private function localeOptions(): array
    {
        $labels = ['hr' => 'Hrvatski (Croatian)', 'en' => 'English'];

        return array_map(
            fn ($code): array => ['value' => (string) $code, 'label' => $labels[$code] ?? strtoupper((string) $code)],
            (array) config('app.supported_locales', []),
        );
    }
}
