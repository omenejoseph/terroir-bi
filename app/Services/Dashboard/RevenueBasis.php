<?php

declare(strict_types=1);

namespace App\Services\Dashboard;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\TenantSetting;
use App\Tenancy\Contracts\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * When an order counts as revenue on the dashboard — a per-tenant setting.
 *
 * `shipped` (default): only SHIPPED orders count, dated by the moment they
 * first shipped (that is when they are invoiced); an order with no shipped
 * history falls back to its backorder date, then its creation date.
 * `created`: every order counts, dated by when it was placed.
 *
 * Every revenue figure on the dashboard goes through apply(), so the cards
 * cannot disagree about the basis. Order analytics stays on creation date.
 */
class RevenueBasis
{
    public const SHIPPED = 'shipped';

    public const CREATED = 'created';

    public const OPTIONS = [self::SHIPPED, self::CREATED];

    public function __construct(private readonly TenantContext $tenant) {}

    public function isShipped(): bool
    {
        $settings = $this->tenant->current()?->settings;
        $basis = $settings instanceof TenantSetting ? $settings->getAttribute('revenue_basis') : null;

        return $basis !== self::CREATED;
    }

    /**
     * SQL date expression for the order's revenue date (references the `orders` table).
     *
     * @return literal-string
     */
    public function dateExpression(): string
    {
        if (! $this->isShipped()) {
            return 'orders.created_at';
        }

        return "COALESCE((SELECT MIN(h.created_at) FROM order_status_histories h WHERE h.order_id = orders.id AND h.status = 'SHIPPED'), orders.backorder_date, orders.created_at)";
    }

    /**
     * Restrict an `orders` query to revenue-bearing orders inside the window.
     *
     * @param  Builder<Order>  $query
     * @param  list<string>  $excludedCustomers
     * @return Builder<Order>
     */
    public function apply(Builder $query, array $excludedCustomers, ?Carbon $since, ?Carbon $until): Builder
    {
        $expr = $this->dateExpression();

        $query->where('orders.is_consignment', false)
            ->whereNotIn('orders.customer_id', $excludedCustomers);

        if ($this->isShipped()) {
            $query->where('orders.status', OrderStatus::Shipped->value);
        }

        if ($since !== null) {
            $query->whereRaw("{$expr} >= ?", [$since]);
        }
        if ($until !== null) {
            $query->whereRaw("{$expr} <= ?", [$until]);
        }

        return $query;
    }
}
