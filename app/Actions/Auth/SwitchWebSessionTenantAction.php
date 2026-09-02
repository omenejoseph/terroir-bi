<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Models\Tenant;
use App\Models\User;
use App\Services\Auth\ActiveTenantSession;
use App\Services\Auth\TenantSwitcher;

/**
 * Session equivalent of SwitchTenantAction: rebinds the active tenant in the
 * session rather than minting a new token. Membership is authorized through the
 * shared TenantSwitcher.
 */
class SwitchWebSessionTenantAction
{
    public function __construct(
        private readonly TenantSwitcher $switcher,
        private readonly ActiveTenantSession $activeTenant,
    ) {}

    public function execute(User $user, string $tenantId): Tenant
    {
        $tenant = $this->switcher->authorize($user, $tenantId);

        $this->activeTenant->set($tenant);

        return $tenant;
    }
}
