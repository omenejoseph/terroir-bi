<?php

declare(strict_types=1);

namespace App\Actions\Members;

use App\Enums\MembershipStatus;
use App\Models\Membership;

/**
 * Reinstates a removed teammate — RemoveMemberAction's own counterpart, now
 * that Membership soft-deletes (see the 2026_09_06_184137 migration) rather
 * than deleting a row for good. Restored as `suspended`, not `active`: the
 * membership going away is not evidence they should walk back in with full
 * access unreviewed — an explicit "Reactivate" (a status update) after this
 * is a second, deliberate step, the same one used to unsuspend anyone else.
 */
class RestoreMemberAction
{
    public function execute(Membership $membership): void
    {
        $membership->restore();
        $membership->forceFill(['status' => MembershipStatus::Suspended])->save();
    }
}
