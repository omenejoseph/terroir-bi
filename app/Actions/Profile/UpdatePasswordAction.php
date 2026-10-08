<?php

declare(strict_types=1);

namespace App\Actions\Profile;

use App\Models\User;
use App\Services\Audit\AuditLogger;

class UpdatePasswordAction
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * Sets the new password (hashed by the model cast) and signs the person out of every API token,
     * so a password change really does end access from anything that held the old credentials.
     * The browser session they are using stays signed in.
     */
    public function execute(User $user, string $password): void
    {
        $user->forceFill(['password' => $password])->save();
        $user->tokens()->toBase()->delete();

        // Never record the password or its hash, only the fact.
        $this->audit->record($user, 'user.password_changed', $user);
    }
}
