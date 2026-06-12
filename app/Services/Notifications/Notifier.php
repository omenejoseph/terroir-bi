<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use App\DataTransferObjects\PushMessageData;
use App\Enums\NotificationType;
use App\Enums\TenantRole;
use App\Jobs\SendWebPushNotification;
use App\Models\AiImport;
use App\Models\Membership;
use App\Models\Notification;
use App\Models\Order;

/**
 * Persists the in-app notification feed and fans the same event out to web push.
 * The feed (Notification rows) is the durable source of truth; the push is a
 * best-effort transport queued after each write. Notifications are path-free:
 * they carry a `type` + a `data` bag of route params, and each client maps
 * (type, data) to its own route — the server never emits client paths.
 */
class Notifier
{
    private const ORDER_ROLES = [TenantRole::Admin, TenantRole::Team, TenantRole::Orders];

    /**
     * @param  iterable<string|null>  $userIds
     * @param  array<string, string>  $data
     */
    public function notifyMany(iterable $userIds, NotificationType $type, string $title, ?string $body, array $data, ?string $actorId): void
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
                'data' => $data,
                'actor_id' => $actorId,
            ]);

            // Best-effort push to the user's devices; queued so it never blocks
            // the caller (and needs no tenant context — subscriptions are global).
            SendWebPushNotification::dispatch(
                $userId,
                new PushMessageData($title, $body, $type, $data),
            );
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
            $this->orderData($order),
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
            $this->orderData($order),
            $actorId,
        );
    }

    /**
     * @param  list<string>  $mentions
     */
    public function orderComment(Order $order, string $content, array $mentions, string $authorId): void
    {
        $this->notifyMany($mentions, NotificationType::Mention, "Mentioned on {$order->order_number}", $content, $this->orderData($order), $authorId);

        $followers = array_diff($this->followerIds($order), $mentions, [$authorId]);
        $this->notifyMany($followers, NotificationType::Reply, "New comment on {$order->order_number}", $content, $this->orderData($order), $authorId);
    }

    /** AI extraction finished and is ready for the uploader to review. */
    public function aiImportReady(AiImport $import, int $lineCount): void
    {
        $this->notifyMany(
            [$import->created_by_id],
            NotificationType::AiImportReady,
            "{$import->type->label()} ready to review",
            $lineCount === 1 ? '1 item extracted' : "{$lineCount} items extracted",
            $this->aiImportData($import),
            null,
        );
    }

    /** AI extraction failed; let the uploader know so they can retry. */
    public function aiImportFailed(AiImport $import): void
    {
        $this->notifyMany(
            [$import->created_by_id],
            NotificationType::AiImportFailed,
            "{$import->type->label()} couldn’t be processed",
            $import->source_filename,
            $this->aiImportData($import),
            null,
        );
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

    /**
     * Route params for an order notification. Clients map this to their own
     * destination (web: /orders/{id}); the server stays path-agnostic.
     *
     * @return array<string, string>
     */
    private function orderData(Order $order): array
    {
        return ['order_id' => (string) $order->getKey()];
    }

    /**
     * Route params for an AI-import notification (web: /ai-imports/{id}).
     *
     * @return array<string, string>
     */
    private function aiImportData(AiImport $import): array
    {
        return ['ai_import_id' => (string) $import->getKey()];
    }
}
