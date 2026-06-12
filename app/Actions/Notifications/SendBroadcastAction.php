<?php

declare(strict_types=1);

namespace App\Actions\Notifications;

use App\DataTransferObjects\PushMessageData;
use App\Enums\MembershipStatus;
use App\Enums\NotificationType;
use App\Jobs\SendWebPushNotification;
use App\Models\Membership;
use App\Models\Notification;
use Illuminate\Support\Facades\DB;

/**
 * Super-admin announcement: writes an in-app feed row for every active member of
 * the targeted tenants (so it appears in each tenant's bell) and fires a single
 * web push per recipient device-set. Runs without tenant context — feed rows are
 * created with an explicit tenant_id (the BelongsToTenant guard only trips when a
 * tenant is bound). `ANNOUNCEMENT` carries no route data; clients display only.
 */
class SendBroadcastAction
{
    /**
     * @param  list<string>|null  $tenantIds  null/empty = all tenants
     * @return array{tenants: int, recipients: int, notifications: int}
     */
    public function execute(string $title, ?string $body, ?array $tenantIds = null): array
    {
        $memberships = Membership::query()
            ->where('status', MembershipStatus::Active->value)
            ->when($tenantIds !== null && $tenantIds !== [], fn ($q) => $q->whereIn('tenant_id', $tenantIds))
            ->get(['user_id', 'tenant_id']);

        $userIds = [];
        $tenants = [];

        DB::transaction(function () use ($memberships, $title, $body, &$userIds, &$tenants): void {
            foreach ($memberships as $membership) {
                // tenant_id is not fillable (normally set by the BelongsToTenant
                // hook from context); set it directly so the cross-tenant write
                // doesn't fall back to demanding a bound tenant.
                $notification = new Notification([
                    'user_id' => $membership->user_id,
                    'type' => NotificationType::Announcement,
                    'title' => $title,
                    'body' => $body,
                    'data' => [],
                ]);
                $notification->tenant_id = $membership->tenant_id;
                $notification->save();

                $userIds[(string) $membership->user_id] = true;
                $tenants[(string) $membership->tenant_id] = true;
            }
        });

        // One push per distinct user (a user in several targeted tenants still
        // gets a single device notification).
        $message = new PushMessageData($title, $body, NotificationType::Announcement);
        foreach (array_keys($userIds) as $userId) {
            SendWebPushNotification::dispatch($userId, $message);
        }

        return [
            'tenants' => count($tenants),
            'recipients' => count($userIds),
            'notifications' => $memberships->count(),
        ];
    }
}
