<?php

declare(strict_types=1);

namespace App\Services\Auth;

use Illuminate\Contracts\Session\Session;

/**
 * Stores the platform admin's own user id for the duration of an
 * impersonation, so the session's authenticated user can swap to the target
 * while still remembering who to restore on "Stop impersonating" — the same
 * session-key-wrapper shape as ActiveTenantSession, keyed separately.
 */
class ImpersonationSession
{
    public const KEY = 'impersonator_id';

    public function __construct(private readonly Session $session) {}

    public function get(): ?string
    {
        $id = $this->session->get(self::KEY);

        return is_string($id) && $id !== '' ? $id : null;
    }

    public function set(string $impersonatorId): void
    {
        $this->session->put(self::KEY, $impersonatorId);
    }

    public function forget(): void
    {
        $this->session->forget(self::KEY);
    }
}
