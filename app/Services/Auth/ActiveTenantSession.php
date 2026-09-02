<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Models\Tenant;
use Illuminate\Contracts\Session\Session;

/**
 * Stores the active tenant id for cookie/session-authenticated (Inertia)
 * requests.
 *
 * The token flow binds its tenant to the Sanctum token; the session flow has no
 * token, so the choice lives here instead. ResolveTenant's "session" strategy
 * reads this value and still verifies membership before making the tenant
 * current, so a tampered session cookie cannot grant access to another tenant.
 */
class ActiveTenantSession
{
    public const KEY = 'active_tenant_id';

    public function __construct(private readonly Session $session) {}

    public function get(): ?string
    {
        $id = $this->session->get(self::KEY);

        return is_string($id) && $id !== '' ? $id : null;
    }

    public function set(?Tenant $tenant): void
    {
        if ($tenant === null) {
            $this->forget();

            return;
        }

        $this->session->put(self::KEY, $tenant->getKey());
    }

    public function forget(): void
    {
        $this->session->forget(self::KEY);
    }
}
