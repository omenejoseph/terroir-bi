<?php

declare(strict_types=1);

namespace App\Queries;

use App\Enums\OrderStatus;
use App\Models\Customer;
use App\Models\CustomerPrice;
use App\Models\Order;
use App\Support\Money\CurrencyRegistry;
use App\Support\Money\Money;
use App\Support\OrderCadence;
use App\Tenancy\Contracts\TenantContext;
use Illuminate\Support\Carbon;

/**
 * The "Needs attention" band on a customer's overview (Figma 231:9336).
 *
 * Three checks, each of which either fires with the numbers behind it or does
 * not appear at all. A card that says "0 orders not shipped" is noise; the band
 * exists to be empty most of the time.
 */
class CustomerAttentionQuery
{
    public function __construct(private readonly TenantContext $tenant) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function get(Customer $customer): array
    {
        return array_values(array_filter([
            $this->reorderOverdue($customer),
            $this->ordersNotShipped($customer),
            $this->noPricingRules($customer),
        ]));
    }

    /**
     * Silent for longer than this customer's own rhythm. Muted once someone has
     * marked them contacted, until their next order clears the flag.
     *
     * @return array<string, mixed>|null
     */
    private function reorderOverdue(Customer $customer): ?array
    {
        /** @var list<Carbon> $dates */
        $dates = Order::query()
            ->where('customer_id', $customer->getKey())
            ->orderBy('created_at')
            ->pluck('created_at')
            ->filter()
            ->map(fn (mixed $d): Carbon => Carbon::parse((string) $d))
            ->values()
            ->all();

        if (count($dates) < 3) {
            return null;
        }

        $median = OrderCadence::medianGapDays($dates);
        $last = end($dates);
        $daysSince = (int) $last->diffInDays(Carbon::now());

        if ($median <= 0 || $daysSince <= $median) {
            return null;
        }

        // An operator who has already called them should not be told again.
        $contacted = $customer->reorder_contacted_at;
        if ($contacted !== null && $contacted->greaterThan($last)) {
            return null;
        }

        $account = (int) Order::query()
            ->where('customer_id', $customer->getKey())
            ->where('is_consignment', false)
            ->sum('total_amount');

        return [
            'key' => 'reorder_overdue',
            'severity' => 'critical',
            'label' => 'Reorder overdue',
            'value' => $daysSince.' days',
            'detail' => sprintf(
                'Last order %s · expected %s',
                $last->format('M j'),
                $last->copy()->addDays((int) round($median))->format('M j'),
            ),
            'meta' => Money::fromMinor($account, $this->currency())->jsonSerialize(),
            'action' => 'contact',
        ];
    }

    /**
     * Money committed but not out the door.
     *
     * @return array<string, mixed>|null
     */
    private function ordersNotShipped(Customer $customer): ?array
    {
        $open = Order::query()
            ->where('customer_id', $customer->getKey())
            ->where('status', '!=', OrderStatus::Shipped)
            ->orderByDesc('created_at')
            ->get(['id', 'order_number', 'total_amount']);

        if ($open->isEmpty()) {
            return null;
        }

        $total = Order::query()
            ->where('customer_id', $customer->getKey())
            ->count();

        return [
            'key' => 'orders_not_shipped',
            'severity' => 'warning',
            'label' => 'Orders not shipped',
            'value' => Money::fromMinor(
                (int) $open->sum(fn (Order $o): int => $o->total_amount->getMinorAmount()),
                $this->currency(),
            )->jsonSerialize(),
            'detail' => $open->take(2)->map(fn (Order $o): string => '#'.$o->order_number)->implode(' · '),
            'meta' => $open->count().' of '.$total.' orders',
            'action' => 'orders',
        ];
    }

    /**
     * A rebate with no rule behind it: every order's discount is being typed by
     * hand, which is the state the pricing engine exists to remove.
     *
     * @return array<string, mixed>|null
     */
    private function noPricingRules(Customer $customer): ?array
    {
        $rebate = (float) $customer->effectiveRebatePercent();

        if ($rebate <= 0) {
            return null;
        }

        $hasTier = $customer->pricing_tier_id !== null;
        $hasOverrides = CustomerPrice::query()->where('customer_id', $customer->getKey())->exists();

        if ($hasTier || $hasOverrides) {
            return null;
        }

        return [
            'key' => 'no_pricing_rules',
            'severity' => 'warning',
            'label' => 'No pricing rules',
            'value' => rtrim(rtrim(number_format($rebate, 2, '.', ''), '0'), '.').'%',
            'detail' => 'Rebate applied by hand on every order',
            'meta' => 'Pricing tab is empty',
            'action' => 'pricing',
        ];
    }

    private function currency(): string
    {
        return $this->tenant->current()?->settings()->first()?->default_currency
            ?? CurrencyRegistry::default()->code;
    }
}
