<?php

declare(strict_types=1);

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Tenancy\BelongsToTenant;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $name
 * @property string $email
 * @property string $role Comma-separated roles, e.g. "ADMIN" or "TEAM,CELLAR".
 */
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use BelongsToTenant;

    use HasFactory;
    use HasUlids;
    use Notifiable;

    protected $fillable = [
        'tenant_id',
        'name',
        'email',
        'password',
        'role',
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
        ];
    }

    /**
     * The roles held by this user (the comma-separated `role` string, split).
     *
     * @return list<string>
     */
    public function roles(): array
    {
        return array_values(array_filter(array_map('trim', explode(',', (string) $this->role))));
    }

    public function hasRole(string $role): bool
    {
        return in_array($role, $this->roles(), true);
    }
}
