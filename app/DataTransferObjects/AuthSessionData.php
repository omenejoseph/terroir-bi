<?php

declare(strict_types=1);

namespace App\DataTransferObjects;

use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;

/**
 * The result of authenticating or switching tenant: the (optional new) token,
 * the user, the active tenant + roles, and the full membership list for a tenant
 * switcher. Transport-agnostic — feeds the API and a Livewire/Inertia frontend.
 *
 * @implements Arrayable<string, mixed>
 */
final class AuthSessionData implements Arrayable, JsonSerializable
{
    /**
     * @param  list<string>  $roles
     * @param  list<TenantMembershipData>  $tenants
     */
    public function __construct(
        public readonly UserData $user,
        public readonly ?string $token,
        public readonly ?string $activeTenantId,
        public readonly array $roles,
        public readonly array $tenants,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'token' => $this->token,
            'user' => $this->user->toArray(),
            'active_tenant_id' => $this->activeTenantId,
            'roles' => $this->roles,
            'tenants' => array_map(fn (TenantMembershipData $t) => $t->toArray(), $this->tenants),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
