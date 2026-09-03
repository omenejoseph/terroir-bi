<?php

declare(strict_types=1);

namespace App\Queries;

use App\Models\Notification;
use Illuminate\Database\Eloquent\Collection;

/**
 * A member's own notification feed, newest first, capped at 50 — shared by
 * the API and web notification controllers so "what a member sees" is defined
 * once.
 */
class ListNotificationsQuery
{
    /**
     * @return Collection<int, Notification>
     */
    public function forUser(string $userId, bool $unreadOnly = false): Collection
    {
        return Notification::query()
            ->where('user_id', $userId)
            ->when($unreadOnly, fn ($query) => $query->where('is_read', false))
            ->orderByDesc('created_at')
            ->limit(50)
            ->get();
    }
}
