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
use Illuminate\Support\Str;

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
            $now = now();

            // Built by hand (id/timestamps/cast included) rather than one
            // Notification::create()/save() per membership, then written in
            // chunks — a broadcast to "all tenants" can mean one row per
            // active membership on the whole platform.
            $rows = [];
            foreach ($memberships as $membership) {
                $userIds[(string) $membership->user_id] = true;
                $tenants[(string) $membership->tenant_id] = true;

                $rows[] = [
                    'id' => (string) Str::ulid(),
                    // tenant_id is not fillable (normally set by the
                    // BelongsToTenant hook from context); a raw insert has no
                    // hook to run at all, so it's given directly either way.
                    'tenant_id' => $membership->tenant_id,
                    'user_id' => $membership->user_id,
                    'type' => NotificationType::Announcement->value,
                    'title' => $title,
                    'body' => $body,
                    'data' => json_encode([]),
                    'is_read' => false,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            // withoutTenant(): this action runs across every targeted
            // tenant (or the whole platform) with no tenant bound, the same
            // audited escape hatch PlatformDashboardQuery uses for its own
            // cross-tenant reads.
            foreach (array_chunk($rows, 500) as $chunk) {
                Notification::withoutTenant()->insert($chunk);
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
