<?php

declare(strict_types=1);

namespace App\Actions\Shortcuts;

use App\Models\User;
use App\Models\UserNavVisit;
use Illuminate\Support\Carbon;

/**
 * Records that a member just visited a nav page, for Manage Shortcuts'
 * "Recent" list (Figma `143:4179`). One row per (tenant, user, nav_key) is
 * kept up to date rather than appended to — the UI only ever wants the most
 * recent visit per page, never a full history.
 */
class RecordNavVisitAction
{
    public function execute(User $user, string $navKey): void
    {
        UserNavVisit::query()->updateOrCreate(
            ['user_id' => $user->getKey(), 'nav_key' => $navKey],
            ['visited_at' => Carbon::now()],
        );
    }
}
