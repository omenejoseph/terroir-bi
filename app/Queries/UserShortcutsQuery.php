<?php

declare(strict_types=1);

namespace App\Queries;

use App\Models\User;
use App\Models\UserNavVisit;
use App\Models\UserShortcut;

/**
 * Reads for Manage Shortcuts (Figma `143:4179`): a member's pinned keys in
 * pin order, and their most-recently-visited keys not already pinned.
 */
class UserShortcutsQuery
{
    /** @return list<string> */
    public function pinned(User $user): array
    {
        return UserShortcut::query()
            ->where('user_id', $user->getKey())
            ->orderBy('position')
            ->pluck('nav_key')
            ->all();
    }

    /**
     * Newest first, capped to what the "Recent" section has room to show.
     *
     * @return list<string>
     */
    public function recent(User $user, int $limit = 5): array
    {
        return UserNavVisit::query()
            ->where('user_id', $user->getKey())
            ->orderByDesc('visited_at')
            ->limit($limit)
            ->pluck('nav_key')
            ->all();
    }
}
