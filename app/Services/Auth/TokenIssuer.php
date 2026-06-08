<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Models\Tenant;
use App\Models\User;

/**
 * Issues Sanctum personal access tokens bound to an active tenant. The token's
 * tenant_id is what ResolveTenant reads to establish tenant context.
 */
class TokenIssuer
{
    public function issue(User $user, ?Tenant $tenant, string $name = 'api'): string
    {
        $newToken = $user->createToken($name);

        if ($tenant !== null) {
            $newToken->accessToken->forceFill(['tenant_id' => $tenant->getKey()])->save();
        }

        return $newToken->plainTextToken;
    }
}
