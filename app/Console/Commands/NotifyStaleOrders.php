<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\NotificationType;
use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\Tenant;
use App\Services\Notifications\Notifier;
use App\Tenancy\Contracts\TenantContext;
use Illuminate\Console\Command;

/**
 * Nudges order-role users about unshipped orders that have sat idle for over a
 * day, de-duplicated via last_stale_notified_at so each order is flagged at most
 * once per day. Runs per tenant.
 */
class NotifyStaleOrders extends Command
{
    protected $signature = 'orders:stale';

    protected $description = 'Notify order-role users about unshipped orders idle for over 24 hours.';

    public function handle(Notifier $notifier, TenantContext $context): int
    {
        $cutoff = now()->subDay();
        $flagged = 0;

        Tenant::query()->each(function (Tenant $tenant) use ($notifier, $context, $cutoff, &$flagged): void {
            $context->makeCurrent($tenant);

            $stale = Order::query()
                ->where('status', '!=', OrderStatus::Shipped)
                ->where('updated_at', '<', $cutoff)
                ->where(fn ($q) => $q->whereNull('last_stale_notified_at')->orWhere('last_stale_notified_at', '<', $cutoff))
                ->get();

            foreach ($stale as $order) {
                $notifier->notifyMany(
                    $notifier->orderRoleUserIds($order),
                    NotificationType::OrderStatus,
                    "Order {$order->order_number} is still {$order->status->value}",
                    'Idle for over 24 hours',
                    "/orders/{$order->getKey()}",
                    null,
                );

                $order->last_stale_notified_at = now();
                $order->save();
                $flagged++;
            }

            $context->forget();
        });

        $this->info("Flagged {$flagged} stale order(s).");

        return self::SUCCESS;
    }
}
