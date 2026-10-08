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

    /** Set only for a tenant admin impersonating a team member; confines the session to that tenant. */
    public const TENANT_KEY = 'impersonation_tenant_id';

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

    /** The tenant this impersonation is confined to, or null (platform-admin impersonation is unconfined). */
    public function tenantId(): ?string
    {
        $id = $this->session->get(self::TENANT_KEY);

        return is_string($id) && $id !== '' ? $id : null;
    }

    public function confineToTenant(string $tenantId): void
    {
        $this->session->put(self::TENANT_KEY, $tenantId);
    }

    public function forget(): void
    {
        $this->session->forget([self::KEY, self::TENANT_KEY]);
    }
}
