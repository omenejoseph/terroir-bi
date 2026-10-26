<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Actions\Onboarding\ApplyWelcomeAnswersAction;
use App\Actions\Onboarding\CreateQuickProductsAction;
use App\Actions\Onboarding\UpdateOnboardingStateAction;
use App\Authorization\TenantModules;
use App\Enums\InventoryCategory;
use App\Enums\Module;
use App\Http\Controllers\Controller;
use App\Http\Requests\Onboarding\StoreQuickProductsRequest;
use App\Http\Requests\Onboarding\StoreWelcomeAnswersRequest;
use App\Http\Requests\Onboarding\UpdateOnboardingStepRequest;
use App\Models\InventoryItem;
use App\Models\PricingTier;
use App\Models\ProductCategory;
use App\Models\Tenant;
use App\Services\Onboarding\OnboardingStatus;
use App\Services\Onboarding\OnboardingStep;
use App\Tenancy\Contracts\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The new-account setup hub (nav's "System · Setup", capability
 * `settings.manage`): the go-live checklist from OnboardingStatus, plus the
 * few writes the checklist owns — skipping a step, marking one done by hand,
 * and dismissing the dashboard reminder. Most steps link out to the existing
 * screen that does the work (see resources/js/lib/onboarding.ts); two have
 * screens of their own here, because nothing existing fits a first setup:
 * the welcome questions, and the quick product grid.
 */
class SetupController extends Controller
{
    public function index(TenantContext $tenant, OnboardingStatus $status): Response
    {
        $current = $this->tenant($tenant);

        return Inertia::render('Setup/Index', [
            'steps' => array_map(fn (OnboardingStep $step): array => $step->toArray(), $status->steps($current)),
            'summary' => $status->summary($current),
        ]);
    }

    /** Three questions whose answers suggest starter categories and pricing tiers. */
    public function welcome(TenantContext $tenant): Response
    {
        $current = $this->tenant($tenant);
        $modules = TenantModules::keysFor($current);

        return Inertia::render('Setup/Welcome', [
            'answers' => OnboardingStatus::state($current)['answers'],
            // Suggestions are created in the tenant's language — they become real category / tier names.
            'wineCategories' => array_map(fn (string $name): string => __($name), ApplyWelcomeAnswersAction::WINE_CATEGORIES),
            'otherCategory' => __(ApplyWelcomeAnswersAction::OTHER_CATEGORY),
            'channelTiers' => array_map(fn (string $name): string => __($name), ApplyWelcomeAnswersAction::CHANNEL_TIERS),
            'existingCategories' => in_array(Module::Inventory->value, $modules, true)
                ? ProductCategory::query()->where('type', InventoryCategory::Finished->value)->whereNull('parent_id')->pluck('name')->all()
                : [],
            'existingTiers' => in_array(Module::Customers->value, $modules, true)
                ? PricingTier::query()->pluck('name')->all()
                : [],
        ]);
    }

    public function storeWelcome(StoreWelcomeAnswersRequest $request, TenantContext $tenant, ApplyWelcomeAnswersAction $action): RedirectResponse
    {
        $current = $this->tenant($tenant);
        $modules = TenantModules::keysFor($current);

        /** @var array{sells: string, channels?: list<string>, has_file?: bool, categories?: list<array{name: string, has_vintage?: bool}>, tiers?: list<array{name: string, rebate_percent?: numeric-string|int|float|null}>} $answers */
        $answers = $request->validated();

        // Only create what the plan can use.
        if (! in_array(Module::Inventory->value, $modules, true)) {
            $answers['categories'] = [];
        }
        if (! in_array(Module::Customers->value, $modules, true)) {
            $answers['tiers'] = [];
        }

        $created = $action->execute($current, $answers);

        $message = __('Ready: :categories categories and :tiers pricing tiers added.', $created);

        // A product file goes straight to the import; otherwise back to the checklist.
        return ($answers['has_file'] ?? false) && in_array(Module::Inventory->value, $modules, true)
            ? redirect('/setup/products?setup=finished_products&import=1')->with('success', $message)
            : redirect('/setup')->with('success', $message);
    }

    /** "Not now" on the welcome questions: stops pointing at them, creates nothing. */
    public function skipWelcome(TenantContext $tenant, UpdateOnboardingStateAction $action): RedirectResponse
    {
        $action->answer($this->tenant($tenant), []);

        return redirect('/setup');
    }

    /** The quick product grid — finished wines a row at a time, with CSV import beside it. */
    public function products(TenantContext $tenant): Response
    {
        $this->requireInventory($this->tenant($tenant));

        $categories = ProductCategory::query()
            ->with('parent')
            ->where('type', InventoryCategory::Finished->value)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        return Inertia::render('Setup/Products', [
            // Top-level first, each followed by its subcategories, as "Red · Plavac" labels.
            'categories' => array_values($categories
                ->whereNull('parent_id')
                ->flatMap(fn (ProductCategory $top): array => [
                    ['id' => (string) $top->getKey(), 'label' => $top->name, 'has_vintage' => $top->has_vintage],
                    ...$categories->where('parent_id', $top->getKey())->map(fn (ProductCategory $sub): array => [
                        'id' => (string) $sub->getKey(),
                        'label' => $top->name.' · '.$sub->name,
                        'has_vintage' => $sub->has_vintage,
                    ])->values()->all(),
                ])
                ->all()),
            'productCount' => InventoryItem::query()->where('category', InventoryCategory::Finished->value)->count(),
        ]);
    }

    public function storeProducts(StoreQuickProductsRequest $request, TenantContext $tenant, CreateQuickProductsAction $action): RedirectResponse
    {
        $this->requireInventory($this->tenant($tenant));

        /** @var list<array{name: string, product_category_id?: string|null, vintage?: string|null, unit_size?: string|null, sales_unit: string, bottles_per_case: int, default_price?: int|null, opening_stock?: int|null, is_for_sale?: bool}> $rows */
        $rows = $request->validated('rows');

        $count = $action->execute($rows);

        return back()->with('success', trans_choice(':count product added.|:count products added.', $count));
    }

    public function updateStep(
        UpdateOnboardingStepRequest $request,
        string $step,
        TenantContext $tenant,
        UpdateOnboardingStateAction $action,
    ): RedirectResponse {
        abort_unless(array_key_exists($step, OnboardingStatus::STEPS), 404);

        $current = $this->tenant($tenant);

        if ($request->has('skipped')) {
            if (! in_array($step, OnboardingStatus::SKIPPABLE, true)) {
                throw ValidationException::withMessages(['skipped' => __('This step can\'t be skipped.')]);
            }

            $action->skip($current, $step, $request->boolean('skipped'));
        } else {
            if (! in_array($step, OnboardingStatus::MARKABLE, true)) {
                throw ValidationException::withMessages(['done' => __('This step completes itself once its data is in.')]);
            }

            $action->markDone($current, $step, $request->boolean('done'));
        }

        return back();
    }

    public function dismiss(Request $request, TenantContext $tenant, UpdateOnboardingStateAction $action): RedirectResponse
    {
        $action->dismiss($this->tenant($tenant), $request->boolean('dismissed', true));

        return back();
    }

    /** The product grid only makes sense on a plan with Inventory. */
    private function requireInventory(Tenant $tenant): void
    {
        abort_unless(in_array(Module::Inventory->value, TenantModules::keysFor($tenant), true), 404);
    }

    private function tenant(TenantContext $tenant): Tenant
    {
        $current = $tenant->current();
        abort_unless($current instanceof Tenant, 404);

        return $current;
    }
}
