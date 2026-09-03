<?php

declare(strict_types=1);

namespace App\Queries;

use App\Models\Membership;
use App\Models\User;
use App\Tenancy\Contracts\TenantContext;

/**
 * The current tenant's members, name + email only — for pickers (the @-mention
 * autocomplete) that need to find a teammate but not manage them. Narrower
 * than Api\MemberController's list (no roles/status), and deliberately not
 * gated by members.view: that capability is for member *management*.
 */
class ListTenantMembersQuery
{
    public function __construct(private readonly TenantContext $tenants) {}

    /** @return list<array{id: string, name: string, email: string}> */
    public function list(): array
    {
        return Membership::query()
            ->where('tenant_id', $this->tenants->id())
            ->with('user')
            ->get()
            ->filter(fn (Membership $membership) => $membership->user instanceof User)
            ->map(fn (Membership $membership): array => [
                'id' => $membership->user->getKey(),
                'name' => $membership->user->fullName(),
                'email' => $membership->user->email,
            ])
            ->values()
            ->all();
    }
}
