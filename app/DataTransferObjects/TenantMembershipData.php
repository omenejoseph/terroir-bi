<?php

declare(strict_types=1);

namespace App\DataTransferObjects;

use App\Models\Membership;
use App\Models\Tenant;
use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;
use RuntimeException;

/**
 * One of a user's tenant memberships, summarised for the client (e.g. to render
 * a tenant switcher).
 *
 * @implements Arrayable<string, mixed>
 */
final class TenantMembershipData implements Arrayable, JsonSerializable
{
    /**
     * @param  list<string>  $roles
     */
    public function __construct(
        public readonly string $tenantId,
        public readonly string $tenantName,
        public readonly string $tenantSlug,
        public readonly array $roles,
        public readonly string $status,
    ) {}

    public static function fromModel(Membership $membership): self
    {
        $tenant = $membership->tenant;

        if (! $tenant instanceof Tenant) {
            throw new RuntimeException('Membership is missing its tenant relation.');
        }

        return new self(
            tenantId: $membership->tenant_id,
            tenantName: $tenant->name,
            tenantSlug: $tenant->slug,
            roles: array_values(array_map(fn ($role) => $role->value, $membership->roles->all())),
            status: $membership->status->value,
        );
    }

    /**
     * @return array{tenant_id:string, name:string, slug:string, roles:list<string>, status:string}
     */
    public function toArray(): array
    {
        return [
            'tenant_id' => $this->tenantId,
            'name' => $this->tenantName,
            'slug' => $this->tenantSlug,
            'roles' => $this->roles,
            'status' => $this->status,
        ];
    }

    /**
     * @return array{tenant_id:string, name:string, slug:string, roles:list<string>, status:string}
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
