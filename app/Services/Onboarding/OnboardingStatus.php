<?php

declare(strict_types=1);

namespace App\Services\Onboarding;

use App\Authorization\TenantModules;
use App\Enums\InventoryCategory;
use App\Enums\MembershipStatus;
use App\Enums\Module;
use App\Enums\OnboardingStepStatus;
use App\Models\Customer;
use App\Models\CustomerCategory;
use App\Models\CustomerPrice;
use App\Models\InventoryItem;
use App\Models\Invitation;
use App\Models\Order;
use App\Models\PricingTier;
use App\Models\ProductCategory;
use App\Models\Tenant;
use App\Models\TenantSetting;
use App\Models\TierPrice;
use Illuminate\Database\Eloquent\Builder;
use InvalidArgumentException;

/**
 * The new-account setup checklist ("Go live" — what a tenant needs before it
 * can take its first real order). See docs/21-onboarding-plan.md.
 *
 * A step's status is read off the tenant's real data, not a stored "done"
 * flag: "Finished products" is done because sellable, priced products exist,
 * so it stays true for tenants that arrived by import and turns false again
 * if the data goes away. The only stored state (TenantSetting::$onboarding)
 * is what data can't say — a step the admin skipped ("we don't use tiers"),
 * one they marked done by hand, and whether the dashboard reminder was
 * dismissed.
 *
 * Must run with the tenant bound (TenantContext): the counts below rely on
 * BelongsToTenant's scope.
 */
class OnboardingStatus
{
    /**
     * Step key => the module that owns it. Order is the order the hub shows
     * them in. A step whose module is outside the tenant's plan is left out.
     */
    public const STEPS = [
        'company_profile' => Module::Settings,
        'product_categories' => Module::Inventory,
        'finished_products' => Module::Inventory,
        'opening_stock' => Module::Inventory,
        'pricing_tiers' => Module::Customers,
        'customers' => Module::Customers,
        'team' => Module::Settings,
        // "Sell better" — after go-live; none of these hold up a first order.
        'first_order' => Module::Orders,
        'product_costs' => Module::Inventory,
        'special_prices' => Module::Customers,
        'customer_categories' => Module::Customers,
        'order_portal' => Module::Customers,
        'min_stock' => Module::Inventory,
        'materials' => Module::Inventory,
    ];

    /**
     * The steps of the second section. The rest are "Go live", which is what
     * the dashboard reminder counts — these make an account better, not usable.
     */
    public const SELL_BETTER = ['first_order', 'product_costs', 'special_prices', 'customer_categories', 'order_portal', 'min_stock', 'materials'];

    /** Steps that can be skipped outright — the rest are needed to sell at all (or, for costs and a first order, to trust the numbers). */
    public const SKIPPABLE = ['pricing_tiers', 'team', 'special_prices', 'customer_categories', 'order_portal', 'min_stock', 'materials'];

    /** Steps the data can't fully confirm, so the admin may mark them done by hand. */
    public const MARKABLE = ['opening_stock'];

    /** The company-profile fields that make an order PDF presentable. */
    private const PROFILE_REQUIRED = ['company_oib', 'address', 'city', 'country', 'contact_email'];

    /**
     * @return list<OnboardingStep>
     */
    public function steps(Tenant $tenant): array
    {
        $modules = TenantModules::keysFor($tenant);
        $state = self::state($tenant);
        $steps = [];

        foreach (self::STEPS as $key => $module) {
            if (! in_array($module->value, $modules, true)) {
                continue;
            }

            $steps[] = $this->settle($this->measure($key, $tenant), $state);
        }

        return $steps;
    }

    /**
     * The dashboard reminder's numbers: how many "Go live" steps are settled
     * (done or skipped) out of how many apply to this tenant.
     *
     * `welcomed` is false until the admin has answered (or skipped) the
     * welcome questions, which is what the reminder and hub point to first.
     *
     * @return array{done: int, total: int, complete: bool, dismissed: bool, welcomed: bool}
     */
    public function summary(Tenant $tenant): array
    {
        $steps = array_values(array_filter($this->steps($tenant), fn (OnboardingStep $step): bool => $step->section === 'go_live'));
        $done = count(array_filter($steps, fn (OnboardingStep $step): bool => $step->status->isSettled()));
        $state = self::state($tenant);

        return [
            'done' => $done,
            'total' => count($steps),
            'complete' => $done === count($steps),
            'dismissed' => $state['dismissed_at'] !== null,
            'welcomed' => $state['answers'] !== null,
        ];
    }

    /**
     * The stored part of the checklist, normalised.
     *
     * `answers` is the welcome questions' answers — null until they were
     * answered or skipped (skipping stores an empty array).
     *
     * @return array{skipped: list<string>, done: list<string>, dismissed_at: string|null, answers: array<string, mixed>|null}
     */
    public static function state(Tenant $tenant): array
    {
        $settings = $tenant->settings;
        $stored = $settings instanceof TenantSetting && is_array($settings->onboarding) ? $settings->onboarding : [];
        $answers = $stored['answers'] ?? null;

        return [
            'skipped' => array_values(array_filter((array) ($stored['skipped'] ?? []), 'is_string')),
            'done' => array_values(array_filter((array) ($stored['done'] ?? []), 'is_string')),
            'dismissed_at' => is_string($stored['dismissed_at'] ?? null) ? $stored['dismissed_at'] : null,
            'answers' => is_array($answers) ? $answers : null,
        ];
    }

    /**
     * Folds the stored skip / marked-done state over what the data says.
     * Real data wins: a step the data shows as done reads "done" even if it
     * was skipped earlier.
     *
     * @param  array{skipped: list<string>, done: list<string>, dismissed_at: string|null, answers: array<string, mixed>|null}  $state
     */
    private function settle(OnboardingStep $step, array $state): OnboardingStep
    {
        $status = $step->status;

        if ($status !== OnboardingStepStatus::Done) {
            if (in_array($step->key, self::MARKABLE, true) && in_array($step->key, $state['done'], true)) {
                $status = OnboardingStepStatus::Done;
            } elseif (in_array($step->key, self::SKIPPABLE, true) && in_array($step->key, $state['skipped'], true)) {
                $status = OnboardingStepStatus::Skipped;
            }
        }

        return new OnboardingStep(
            key: $step->key,
            status: $status,
            progress: $step->progress,
            count: $step->count,
            issues: $step->issues,
            skippable: in_array($step->key, self::SKIPPABLE, true),
            markable: in_array($step->key, self::MARKABLE, true),
            section: in_array($step->key, self::SELL_BETTER, true) ? 'sell_better' : 'go_live',
        );
    }

    private function measure(string $key, Tenant $tenant): OnboardingStep
    {
        return match ($key) {
            'company_profile' => $this->companyProfile($tenant),
            'product_categories' => $this->productCategories(),
            'finished_products' => $this->finishedProducts(),
            'opening_stock' => $this->openingStock(),
            'pricing_tiers' => $this->pricingTiers(),
            'customers' => $this->customers(),
            'team' => $this->team($tenant),
            'first_order' => $this->firstOrder(),
            'product_costs' => $this->productCosts(),
            'special_prices' => $this->specialPrices(),
            'customer_categories' => $this->customerCategories(),
            'order_portal' => $this->orderPortal(),
            'min_stock' => $this->minStock(),
            'materials' => $this->materials(),
            default => throw new InvalidArgumentException("Unknown onboarding step [{$key}]."),
        };
    }

    private function companyProfile(Tenant $tenant): OnboardingStep
    {
        $settings = $tenant->settings;
        $filled = 0;

        foreach (self::PROFILE_REQUIRED as $field) {
            $value = $settings?->getAttribute($field);
            $filled += is_string($value) && trim($value) !== '' ? 1 : 0;
        }

        $total = count(self::PROFILE_REQUIRED);
        $issues = [];

        $iban = $settings?->iban;

        if ($filled === $total && ($iban === null || trim($iban) === '')) {
            $issues[] = ['message' => 'No bank account yet — order PDFs will go out without payment details.', 'count' => 1];
        }

        return new OnboardingStep(
            key: 'company_profile',
            status: self::statusFor($filled, $total),
            progress: ['done' => $filled, 'total' => $total],
            issues: $issues,
        );
    }

    private function productCategories(): OnboardingStep
    {
        $count = ProductCategory::query()->where('type', InventoryCategory::Finished->value)->count();

        return new OnboardingStep(
            key: 'product_categories',
            status: $count > 0 ? OnboardingStepStatus::Done : OnboardingStepStatus::NotStarted,
            count: $count,
        );
    }

    private function finishedProducts(): OnboardingStep
    {
        $forSale = $this->forSale()->count();
        $priced = $this->forSale()->where('default_price', '>', 0)->count();
        $issues = [];

        if ($forSale > $priced) {
            $issues[] = ['message' => ':count for-sale products have no list price.', 'count' => $forSale - $priced];
        }

        return new OnboardingStep(
            key: 'finished_products',
            // One priced, sellable product is enough to take an order; the
            // unpriced rest are a nudge, not a blocker.
            status: $priced > 0 ? OnboardingStepStatus::Done : ($forSale > 0 ? OnboardingStepStatus::InProgress : OnboardingStepStatus::NotStarted),
            progress: $forSale > 0 ? ['done' => $priced, 'total' => $forSale] : null,
            count: $forSale,
            issues: $issues,
        );
    }

    private function openingStock(): OnboardingStep
    {
        $forSale = $this->forSale()->count();
        $stocked = $this->forSale()->where('current_stock', '>', 0)->count();
        $issues = [];

        if ($stocked > 0 && $forSale > $stocked) {
            $issues[] = ['message' => ':count for-sale products show no stock.', 'count' => $forSale - $stocked];
        }

        return new OnboardingStep(
            key: 'opening_stock',
            // Some wines are genuinely sold out, so "every product stocked" is
            // the only state the data can call done; anything short of that
            // the admin confirms by hand (MARKABLE).
            status: $forSale > 0 && $stocked === $forSale ? OnboardingStepStatus::Done : self::statusFor($stocked, $forSale),
            progress: $forSale > 0 ? ['done' => $stocked, 'total' => $forSale] : null,
            issues: $issues,
        );
    }

    private function pricingTiers(): OnboardingStep
    {
        $count = PricingTier::query()->count();

        return new OnboardingStep(
            key: 'pricing_tiers',
            status: $count > 0 ? OnboardingStepStatus::Done : OnboardingStepStatus::NotStarted,
            count: $count,
        );
    }

    private function customers(): OnboardingStep
    {
        $count = Customer::query()->where('is_active', true)->count();
        $issues = [];

        if ($count > 0 && PricingTier::query()->exists()) {
            $listPrice = Customer::query()
                ->where('is_active', true)
                ->whereNull('pricing_tier_id')
                ->where('rebate_percent', '<=', 0)
                ->count();

            if ($listPrice > 0) {
                $issues[] = ['message' => ':count customers have no tier or rebate, so they pay list price.', 'count' => $listPrice];
            }
        }

        return new OnboardingStep(
            key: 'customers',
            status: $count > 0 ? OnboardingStepStatus::Done : OnboardingStepStatus::NotStarted,
            count: $count,
            issues: $issues,
        );
    }

    private function team(Tenant $tenant): OnboardingStep
    {
        $members = $tenant->memberships()->where('status', MembershipStatus::Active->value)->count();
        $pending = Invitation::query()->whereNull('accepted_at')->where('expires_at', '>', now())->count();

        return new OnboardingStep(
            key: 'team',
            // The admin doing the setup is the first member; anyone beyond
            // them — joined or invited — means the team step has been done.
            status: $members > 1 || $pending > 0 ? OnboardingStepStatus::Done : OnboardingStepStatus::NotStarted,
            count: $members,
        );
    }

    private function firstOrder(): OnboardingStep
    {
        $count = Order::query()->count();

        return new OnboardingStep(
            key: 'first_order',
            status: $count > 0 ? OnboardingStepStatus::Done : OnboardingStepStatus::NotStarted,
            count: $count,
        );
    }

    /** A cost per unit, or a recipe it can be derived from — without either, margins read 100 %. */
    private function productCosts(): OnboardingStep
    {
        $forSale = $this->forSale()->count();
        $costed = $this->forSale()
            ->where(fn (Builder $q) => $q->whereNotNull('cost_per_unit')->orWhereHas('recipe'))
            ->count();
        $issues = [];

        if ($forSale > $costed) {
            $issues[] = ['message' => ':count for-sale products have no cost, so their margin shows as 100 %.', 'count' => $forSale - $costed];
        }

        return new OnboardingStep(
            key: 'product_costs',
            status: $forSale > 0 && $costed === $forSale ? OnboardingStepStatus::Done : self::statusFor($costed, $forSale),
            progress: $forSale > 0 ? ['done' => $costed, 'total' => $forSale] : null,
            issues: $issues,
        );
    }

    /** Per-product prices on a tier, or negotiated prices for one customer. */
    private function specialPrices(): OnboardingStep
    {
        $count = TierPrice::query()->count() + CustomerPrice::query()->count();

        return new OnboardingStep(
            key: 'special_prices',
            status: $count > 0 ? OnboardingStepStatus::Done : OnboardingStepStatus::NotStarted,
            count: $count,
        );
    }

    private function customerCategories(): OnboardingStep
    {
        $count = CustomerCategory::query()->count();
        $issues = [];

        if ($count > 0) {
            $uncategorised = Customer::query()->where('is_active', true)->whereNull('customer_category_id')->count();

            if ($uncategorised > 0) {
                $issues[] = ['message' => ':count customers have no category yet.', 'count' => $uncategorised];
            }
        }

        return new OnboardingStep(
            key: 'customer_categories',
            status: $count > 0 ? OnboardingStepStatus::Done : OnboardingStepStatus::NotStarted,
            count: $count,
            issues: $issues,
        );
    }

    /** Customers with a self-service order link. */
    private function orderPortal(): OnboardingStep
    {
        $count = Customer::query()->where('is_active', true)->whereNotNull('order_token')->count();

        return new OnboardingStep(
            key: 'order_portal',
            status: $count > 0 ? OnboardingStepStatus::Done : OnboardingStepStatus::NotStarted,
            count: $count,
        );
    }

    /** Reorder points, which drive the low-stock alerts. */
    private function minStock(): OnboardingStep
    {
        $forSale = $this->forSale()->count();
        $withMin = $this->forSale()->whereNotNull('min_stock')->count();

        return new OnboardingStep(
            key: 'min_stock',
            status: $forSale > 0 && $withMin === $forSale ? OnboardingStepStatus::Done : self::statusFor($withMin, $forSale),
            progress: $forSale > 0 ? ['done' => $withMin, 'total' => $forSale] : null,
        );
    }

    /** Semi-finished goods and raw materials — bulk wine, glass, corks, labels, cartons. */
    private function materials(): OnboardingStep
    {
        $count = InventoryItem::query()
            ->whereIn('category', [InventoryCategory::SemiFinished->value, InventoryCategory::RawMaterial->value])
            ->where('is_active', true)
            ->count();

        return new OnboardingStep(
            key: 'materials',
            status: $count > 0 ? OnboardingStepStatus::Done : OnboardingStepStatus::NotStarted,
            count: $count,
        );
    }

    /**
     * Active, finished, for-sale items — what an order form can offer.
     *
     * @return Builder<InventoryItem>
     */
    private function forSale(): Builder
    {
        return InventoryItem::query()
            ->where('category', InventoryCategory::Finished->value)
            ->where('is_active', true)
            ->where('is_for_sale', true);
    }

    private static function statusFor(int $done, int $total): OnboardingStepStatus
    {
        return match (true) {
            $total > 0 && $done >= $total => OnboardingStepStatus::Done,
            $done > 0 => OnboardingStepStatus::InProgress,
            default => OnboardingStepStatus::NotStarted,
        };
    }
}
