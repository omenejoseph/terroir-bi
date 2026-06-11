<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * A billable application module. Plans group modules; a tenant sees only the
 * modules its plan includes. Orthogonal to capabilities/roles (a role decides
 * what you may do; a module decides whether the feature is in your plan).
 */
enum Module: string
{
    case Dashboard = 'dashboard';
    case Inventory = 'inventory';
    case Orders = 'orders';
    case Customers = 'customers';
    case Suppliers = 'suppliers';
    case Inflows = 'inflows';
    case Costs = 'costs';
    case CashFlow = 'cash_flow';
    case WorkOrders = 'work_orders';
    case Team = 'team';
    case Settings = 'settings';

    /** Human label for the back office. */
    public function label(): string
    {
        return match ($this) {
            self::Dashboard => 'Dashboard',
            self::Inventory => 'Inventory',
            self::Orders => 'Orders',
            self::Customers => 'Customers',
            self::Suppliers => 'Suppliers',
            self::Inflows => 'Money in',
            self::Costs => 'Costs',
            self::CashFlow => 'Cash flow',
            self::WorkOrders => 'Work orders',
            self::Team => 'Team',
            self::Settings => 'Settings',
        };
    }

    /** @return list<self> */
    public static function all(): array
    {
        return self::cases();
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $m) => $m->value, self::cases());
    }
}
