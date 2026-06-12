<?php

declare(strict_types=1);

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Models\Contracts\HasName;
use Filament\Panel;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\Contracts\HasAbilities;
use Laravel\Sanctum\HasApiTokens;

/**
 * A global identity. A user is NOT tenant-scoped: they may belong to many
 * tenants via memberships and switch the active one. Roles live on the
 * membership, not here.
 *
 * @property string $id
 * @property string $first_name
 * @property string|null $middle_name
 * @property string $last_name
 * @property string $email
 * @property bool $is_platform_admin
 */
class User extends Authenticatable implements FilamentUser, HasName
{
    /** @use HasApiTokens<HasAbilities> */
    use HasApiTokens;

    /** @use HasFactory<UserFactory> */
    use HasFactory;

    use HasUlids;
    use Notifiable;

    protected $fillable = [
        'first_name',
        'middle_name',
        'last_name',
        'email',
        'password',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            // Not fillable — set only via an admin action, never mass-assigned.
            'is_platform_admin' => 'boolean',
        ];
    }

    /** Only platform admins may access the Filament back office at /admin. */
    public function canAccessPanel(Panel $panel): bool
    {
        return $this->is_platform_admin === true;
    }

    /** Display name for Filament (avatar, account widget). */
    public function getFilamentName(): string
    {
        return $this->fullName();
    }

    /** The user's full name (first [middle] last). */
    public function fullName(): string
    {
        return implode(' ', array_filter([
            $this->first_name,
            $this->middle_name,
            $this->last_name,
        ]));
    }

    /**
     * @return HasMany<Membership, $this>
     */
    public function memberships(): HasMany
    {
        return $this->hasMany(Membership::class);
    }

    /**
     * @return BelongsToMany<Tenant, $this>
     */
    public function tenants(): BelongsToMany
    {
        return $this->belongsToMany(Tenant::class, 'memberships')
            ->withPivot(['roles', 'status'])
            ->withTimestamps();
    }

    /**
     * @return HasMany<PushSubscription, $this>
     */
    public function pushSubscriptions(): HasMany
    {
        return $this->hasMany(PushSubscription::class);
    }

    public function membershipFor(Tenant|string $tenant): ?Membership
    {
        $tenantId = $tenant instanceof Tenant ? $tenant->getKey() : $tenant;

        return $this->memberships()->where('tenant_id', $tenantId)->first();
    }

    public function isMemberOf(Tenant|string $tenant): bool
    {
        return $this->membershipFor($tenant) !== null;
    }
}
