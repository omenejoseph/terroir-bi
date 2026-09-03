<?php

declare(strict_types=1);

namespace App\Actions\Shortcuts;

use App\Models\User;
use App\Models\UserNavVisit;

/** Manage Shortcuts' "Clear all" on the Recent list (Figma `143:4179`). */
class ClearRecentVisitsAction
{
    public function execute(User $user): void
    {
        UserNavVisit::query()->where('user_id', $user->getKey())->delete();
    }
}
