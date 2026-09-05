<?php

declare(strict_types=1);

namespace App\Actions\Users;

use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Validation\ValidationException;

/**
 * Suspends or restores a user account, independent of any tenant membership.
 *
 * Suspending also revokes every Sanctum token: that's what actually cuts off
 * the Next.js SPA's token-authenticated Api\* access immediately — that
 * surface never sees the web session, so a session-only check wouldn't touch
 * it (EnsureUserNotSuspended handles the session-guarded surfaces instead).
 */
class SetUserSuspendedAction
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function execute(User $target, bool $suspended, User $actor): User
    {
        if ($suspended && $target->is($actor)) {
            throw ValidationException::withMessages([
                'suspended' => __('iam.suspend_self'),
            ]);
        }

        $target->suspended_at = $suspended ? now() : null;
        $target->save();

        if ($suspended) {
            // toBase() drops down to the plain query builder for the bulk
            // delete — the relation's own delete() forwarding trips
            // Larastan's morphMany/HasAbilities-collection stubs, and this is
            // the same SQL either way (a DELETE scoped by the morph columns).
            $target->tokens()->toBase()->delete();
        }

        $this->audit->record($actor, $suspended ? 'user.suspended' : 'user.unsuspended', $target);

        return $target;
    }
}
