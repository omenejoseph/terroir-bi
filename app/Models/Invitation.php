<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\TenantRole;
use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Casts\AsEnumCollection;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * A pending invitation to join a tenant. Tenant-scoped for admin listing; the
 * acceptance path looks it up by token via withoutTenant() (the invitee is not
 * yet a member of any tenant).
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $email
 * @property Collection<int, TenantRole> $roles
 * @property string $token
 * @property Carbon $expires_at
 * @property Carbon|null $accepted_at
 */
class Invitation extends Model
{
    use BelongsToTenant;
    use HasUlids;

    protected $fillable = [
        'email',
        'roles',
        'token',
        'invited_by',
        'expires_at',
        'accepted_at',
    ];

    protected function casts(): array
    {
        return [
            'roles' => AsEnumCollection::of(TenantRole::class),
            'expires_at' => 'datetime',
            'accepted_at' => 'datetime',
        ];
    }

    public function isPending(): bool
    {
        return $this->accepted_at === null && ! $this->isExpired();
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }
}
