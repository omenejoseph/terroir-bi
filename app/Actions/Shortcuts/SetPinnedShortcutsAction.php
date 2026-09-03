<?php

declare(strict_types=1);

namespace App\Actions\Shortcuts;

use App\Models\User;
use App\Models\UserShortcut;
use App\Support\NavCatalog;
use Illuminate\Support\Facades\DB;

/**
 * Replaces a member's pinned shortcuts (Manage Shortcuts' Save, Figma
 * `143:4179`) with the given ordered list of keys. Delete-and-reinsert rather
 * than diffing: the whole list is always re-saved together, so there is
 * nothing to diff against, and the set is small enough (a couple dozen keys,
 * at most) that this costs nothing worth optimising away.
 *
 * Unknown keys are dropped rather than rejected — a stale pin surviving a
 * removed nav item should not block saving the rest of the list.
 */
class SetPinnedShortcutsAction
{
    /** @param  list<string>  $keys */
    public function execute(User $user, array $keys): void
    {
        $valid = array_values(array_intersect(array_unique($keys), NavCatalog::ALL_KEYS));

        DB::transaction(function () use ($user, $valid): void {
            UserShortcut::query()->where('user_id', $user->getKey())->delete();

            foreach ($valid as $position => $key) {
                UserShortcut::query()->create([
                    'user_id' => $user->getKey(),
                    'nav_key' => $key,
                    'position' => $position,
                ]);
            }
        });
    }
}
