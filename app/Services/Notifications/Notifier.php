<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use App\Enums\NotificationType;
use App\Enums\TenantRole;
use App\Models\Membership;
use App\Models\Notification;
use App\Models\Order;

/**
 * Persists the in-app notification feed and exposes order-event helpers. Browser
 * push / WhatsApp are best-effort transports to be layered on later; this writes
 * only the durable feed and is safe to call after an order transaction commits.
 */
class Notifier
{
    private const ORDER_ROLES = [TenantRole::Admin, TenantRole::Team, TenantRole::Orders];

    /**
     * @param  iterable<string|null>  $userIds
     */
    public function notifyMany(iterable $userIds, NotificationType $type, string $title, ?string $body, ?string $link, ?string $actorId): void
    {
        $seen = [];
        foreach ($userIds as $userId) {
            if ($userId === null || isset($seen[$userId])) {
                continue;
            }
            $seen[$userId] = true;

            Notification::create([
                'user_id' => $userId,
                'type' => $type,
                'title' => $title,
                'body' => $body,
                'link' => $link,
                'actor_id' => $actorId,
            ]);
        }
    }

    public function orderCreated(Order $order): void
    {
        $recipients = $this->orderRoleUserIds($order);
        $recipients[] = $order->created_by_id; // the creator is notified too

        $this->notifyMany(
            $recipients,
            NotificationType::NewOrder,
            "New order {$order->order_number}",
            $order->customer?->company_name,
            $this->link($order),
            $order->created_by_id,
        );
    }

    public function orderStatusChanged(Order $order, string $actorId): void
    {
        $recipients = array_diff($this->followerIds($order), [$actorId]);

        $this->notifyMany(
            $recipients,
            NotificationType::OrderStatus,
            "Order {$order->order_number} → {$order->status->value}",
            null,
            $this->link($order),
            $actorId,
        );
    }

    /**
     * @param  list<string>  $mentions
     */
    public function orderComment(Order $order, string $content, array $mentions, string $authorId): void
    {
        $this->notifyMany($mentions, NotificationType::Mention, "Mentioned on {$order->order_number}", $content, $this->link($order), $authorId);

        $followers = array_diff($this->followerIds($order), $mentions, [$authorId]);
        $this->notifyMany($followers, NotificationType::Reply, "New comment on {$order->order_number}", $content, $this->link($order), $authorId);
    }

    /**
     * @return list<string>
     */
    public function orderRoleUserIds(Order $order): array
    {
        $ids = Membership::query()
            ->where('tenant_id', $order->tenant_id)
            ->get()
            ->filter(fn (Membership $m) => $m->isActive() && $this->hasOrderRole($m))
            ->map(fn (Membership $m) => (string) $m->user_id)
            ->all();

        return array_values($ids);
    }

    /**
     * @return list<string>
     */
    private function followerIds(Order $order): array
    {
        $commenters = $order->orderNotes()->pluck('author_id')->all();

        return array_values(array_unique([$order->created_by_id, ...$commenters]));
    }

    private function hasOrderRole(Membership $membership): bool
    {
        foreach (self::ORDER_ROLES as $role) {
            if ($membership->hasRole($role)) {
                return true;
            }
        }

        return false;
    }

    private function link(Order $order): string
    {
        return "/orders/{$order->getKey()}";
    }
}
