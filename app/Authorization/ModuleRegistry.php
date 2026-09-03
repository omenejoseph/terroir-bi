<?php

declare(strict_types=1);

namespace App\Authorization;

use App\Enums\Module;

/**
 * Maps each billable Module to the capabilities and API path-prefixes it owns.
 *
 * Modules and capabilities are orthogonal: a capability decides what a role may
 * do; a module decides whether the feature is in the tenant's plan. They are not
 * 1:1 — the finance capabilities are shared by the inflows, costs and cash_flow
 * modules — so route gating uses `module:{key}` middleware applied per route
 * (see routes/api.php), while this registry is the reference + nav source.
 */
final class ModuleRegistry
{
    /**
     * Module → capabilities that live under it. Empty = no extra capability
     * (the feature is available to any tenant member once the plan includes it).
     *
     * @return array<string, list<string>>
     */
    public static function capabilities(): array
    {
        return [
            Module::Dashboard->value => [],
            Module::Inventory->value => ['inventory.view', 'inventory.manage', 'inventory.delete', 'pricing.view', 'pricing.manage'],
            Module::Orders->value => ['orders.view', 'orders.manage', 'orders.backorder', 'orders.delete'],
            Module::Customers->value => ['customers.view', 'customers.manage', 'customers.delete', 'customers.tokens'],
            Module::Suppliers->value => ['suppliers.view', 'suppliers.manage', 'suppliers.delete'],
            Module::Inflows->value => ['finance.view', 'finance.manage', 'finance.delete'],
            Module::Costs->value => ['finance.view', 'finance.manage', 'finance.delete'],
            Module::CashFlow->value => ['finance.view'],
            Module::WorkOrders->value => [],
            Module::Cellar->value => ['cellar.view', 'cellar.manage', 'cellar.delete'],
            Module::Vineyards->value => ['vineyards.view', 'vineyards.manage', 'vineyards.delete'],
            Module::Production->value => ['production.view', 'production.manage', 'production.delete'],
            Module::Team->value => ['members.view', 'members.manage', 'invitations.manage'],
            Module::Settings->value => ['settings.manage', 'translations.manage'],
            Module::AiDataEntry->value => ['ai.use', 'ai.manage'],
        ];
    }

    /**
     * Module → the API path prefixes (relative to /api/v1) it owns. Used to apply
     * `module:{key}` middleware and for documentation; each prefix belongs to
     * exactly one module so gating is unambiguous.
     *
     * @return array<string, list<string>>
     */
    public static function pathPrefixes(): array
    {
        return [
            Module::Dashboard->value => ['dashboard'],
            Module::Inventory->value => ['inventory-items'],
            Module::Orders->value => ['orders', 'order-items', 'order-comments'],
            Module::Customers->value => ['customers', 'pricing-tiers'],
            Module::Suppliers->value => ['suppliers', 'supplier-orders'],
            Module::Inflows->value => ['inflows'],
            Module::Costs->value => ['costs'],
            Module::CashFlow->value => ['cash-flow'],
            Module::WorkOrders->value => ['work-orders'],
            Module::Cellar->value => ['vessels', 'wine-lots', 'enological-products', 'fermentation-templates', 'tasting-reports', 'bottlings', 'cellar'],
            Module::Vineyards->value => ['vineyard-parcels', 'grape-contracts', 'harvest-plans', 'harvest-entries', 'intake-bookings', 'press-fractions'],
            Module::Production->value => ['production-plans'],
            Module::Team->value => ['members', 'invitations'],
            Module::Settings->value => ['settings', 'translations'],
            Module::AiDataEntry->value => ['ai-imports'],
        ];
    }

    /**
     * Module → the Inertia web path prefixes (routes/web.php) it owns. Kept
     * separate from pathPrefixes() because the web routes were not designed
     * to mirror the API's segment names 1:1 (e.g. `/inventory` on the web app
     * vs `/api/v1/inventory-items`) — extend this as more modules are ported
     * from frontend/ (see routes/web.php).
     *
     * @return array<string, list<string>>
     */
    public static function webPathPrefixes(): array
    {
        return [
            Module::Dashboard->value => ['dashboard'],
            Module::Inventory->value => ['inventory', 'inventory-analytics', 'inventory-check', 'inventory-spend', 'inventory-bulk'],
            Module::Orders->value => ['orders'],
            Module::Customers->value => ['customers', 'customers-analytics'],
            Module::WorkOrders->value => ['work-orders'],
        ];
    }

    /** @return list<string> */
    public static function capabilitiesFor(Module $module): array
    {
        return self::capabilities()[$module->value] ?? [];
    }

    /**
     * Which modules expose the given capability (usually one; the finance trio
     * for finance.*).
     *
     * @return list<Module>
     */
    public static function modulesForCapability(string $capability): array
    {
        $modules = [];
        foreach (self::capabilities() as $module => $capabilities) {
            if (in_array($capability, $capabilities, true)) {
                $modules[] = Module::from($module);
            }
        }

        return $modules;
    }
}
