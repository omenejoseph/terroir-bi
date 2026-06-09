<?php

declare(strict_types=1);

namespace App\Authorization;

use App\Authorization\Contracts\Authorizer;
use App\Enums\TenantRole;
use App\Models\Membership;
use Illuminate\Contracts\Container\Container;

/**
 * Holds the active membership (current user within the current tenant) and
 * answers authorization questions about it. Bound into the container by the
 * EnsureActiveMembership middleware after the membership is verified.
 *
 * Fail-closed: with no membership bound, every capability check is denied.
 */
class MembershipContext implements Authorizer
{
    private const BINDING = 'membership';

    public function __construct(private readonly Container $app) {}

    public function current(): ?Membership
    {
        return $this->app->bound(self::BINDING)
            ? $this->app->make(self::BINDING)
            : null;
    }

    public function set(Membership $membership): void
    {
        $this->app->instance(self::BINDING, $membership);
    }

    public function forget(): void
    {
        if ($this->app->bound(self::BINDING)) {
            $this->app->forgetInstance(self::BINDING);
        }
    }

    public function check(): bool
    {
        return $this->current() !== null;
    }

    /**
     * @return list<TenantRole>
     */
    public function roles(): array
    {
        $membership = $this->current();

        if ($membership === null) {
            return [];
        }

        return array_values($membership->roles->all());
    }

    public function hasRole(TenantRole $role): bool
    {
        return in_array($role, $this->roles(), true);
    }

    public function can(string $capability): bool
    {
        foreach ($this->roles() as $role) {
            if (RoleCapabilities::roleGrants($role, $capability)) {
                return true;
            }
        }

        return false;
    }

    /** Whether the active member may see money/margin/cost figures. */
    public function canSeeFinancials(): bool
    {
        return $this->can('financials.view');
    }

    /** Whether the active member may edit orders past the 1-hour window. */
    public function canEditOrders(): bool
    {
        return $this->current()?->canEditOrders() ?? false;
    }

    /** Whether the active member may see SHIPPED orders. */
    public function canSeeShippedOrders(): bool
    {
        return $this->current()?->canSeeShippedOrders() ?? false;
    }
}
