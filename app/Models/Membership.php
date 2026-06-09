<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\MembershipStatus;
use App\Enums\TenantRole;
use Illuminate\Database\Eloquent\Casts\AsEnumCollection;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Collection;

/**
 * A user's membership of a tenant and the roles they hold there.
 *
 * Intentionally NOT BelongsToTenant: memberships are read both per-tenant (list
 * members of the current tenant) and per-user (list a user's tenants), so the
 * tenant scope is applied explicitly where needed rather than globally.
 *
 * @property string $id
 * @property string $user_id
 * @property string $tenant_id
 * @property Collection<int, TenantRole> $roles
 * @property MembershipStatus $status
 * @property bool $can_edit_orders
 * @property bool $can_see_shipped_orders
 */
class Membership extends Model
{
    use HasUlids;

    protected $fillable = [
        'user_id',
        'tenant_id',
        'roles',
        'status',
        'can_edit_orders',
        'can_see_shipped_orders',
        'invited_by',
        'joined_at',
    ];

    protected function casts(): array
    {
        return [
            'roles' => AsEnumCollection::of(TenantRole::class),
            'status' => MembershipStatus::class,
            'can_edit_orders' => 'boolean',
            'can_see_shipped_orders' => 'boolean',
            'joined_at' => 'datetime',
        ];
    }

    protected $attributes = [
        'status' => 'active',
        'can_edit_orders' => false,
        'can_see_shipped_orders' => false,
    ];

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function hasRole(TenantRole $role): bool
    {
        return $this->roles->contains($role);
    }

    public function isActive(): bool
    {
        return $this->status === MembershipStatus::Active;
    }

    public function isAdmin(): bool
    {
        return $this->hasRole(TenantRole::Admin);
    }

    /** ADMIN may always edit; otherwise the per-membership flag applies. */
    public function canEditOrders(): bool
    {
        return $this->isAdmin() || $this->can_edit_orders;
    }

    /** ADMIN always sees shipped orders; otherwise the per-membership flag applies. */
    public function canSeeShippedOrders(): bool
    {
        return $this->isAdmin() || $this->can_see_shipped_orders;
    }
}
