<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One significant action, written through App\Services\Audit\AuditLogger —
 * never assembled ad hoc, so every entry carries the same shape.
 *
 * `tenant_id` is nullable and deliberately *not* managed by
 * App\Tenancy\BelongsToTenant: this table holds both tenant-scoped rows
 * (ordinary business actions, auto-stamped with the tenant bound at the time)
 * and platform-level rows with no tenant at all (e.g. impersonation started by
 * a platform admin who isn't bound to any tenant). Scoping a read to one
 * tenant is a plain `where('tenant_id', ...)` — see App\Http\Controllers\Web\
 * LogController — never a global scope.
 *
 * @property string $id
 * @property string|null $tenant_id
 * @property string|null $actor_id
 * @property string $action
 * @property string|null $subject_type
 * @property string|null $subject_id
 * @property array<string, mixed>|null $metadata
 */
class AuditLog extends Model
{
    use HasUlids;

    protected $fillable = [
        'tenant_id',
        'actor_id',
        'action',
        'subject_type',
        'subject_id',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function actor(): BelongsTo
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
}
