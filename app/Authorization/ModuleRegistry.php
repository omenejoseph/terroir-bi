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
            Module::Team->value => ['members.view', 'members.manage', 'invitations.manage'],
            Module::Settings->value => ['settings.manage', 'translations.manage'],
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
            Module::Team->value => ['members', 'invitations'],
            Module::Settings->value => ['settings', 'translations'],
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
