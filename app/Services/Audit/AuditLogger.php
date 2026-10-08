<?php

declare(strict_types=1);

namespace App\Services\Audit;

use App\Models\AuditLog;
use App\Models\User;
use App\Services\Auth\ImpersonationSession;
use App\Tenancy\Contracts\TenantContext;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;

/**
 * Records one entry to `audit_logs`. Written both from this app's original
 * security-sensitive actions (user suspension, password-reset triggers,
 * impersonation start/stop — no tenant bound at that point, so those rows
 * land with `tenant_id = null`) and, since App\Services\Audit\Auditable, from
 * every significant business action across the app, which do run inside a
 * bound tenant and so get scoped automatically below.
 *
 * `$actor` takes the framework's `Authenticatable` contract rather than the
 * concrete `User` model, so any call site can pass `Auth::user()` straight
 * through with no narrowing — this app has exactly one guardable model, but
 * the audit trail shouldn't need to know that.
 */
class AuditLogger
{
    public function __construct(
        private readonly TenantContext $tenants,
        private readonly ImpersonationSession $impersonation,
    ) {}

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function record(?Authenticatable $actor, string $action, ?Model $subject = null, array $metadata = []): AuditLog
    {
        $metadata = $this->withImpersonator($actor, $metadata);

        return AuditLog::create([
            // Stamped from whatever tenant happens to be bound to this
            // request, never trusted from the caller — a platform-admin
            // action with no tenant bound correctly lands as tenant_id null.
            'tenant_id' => $this->tenants->check() ? $this->tenants->currentId() : null,
            'actor_id' => $actor?->getAuthIdentifier(),
            'action' => $action,
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject?->getKey(),
            'metadata' => $metadata === [] ? null : $metadata,
        ]);
    }

    /**
     * While an admin is impersonating, `$actor` is the person being impersonated, so the
     * trail would otherwise blame them for what the admin did. Stamp the real person on
     * every entry written during the session (not on the start entry itself, whose actor
     * already is the admin).
     *
     * @param  array<string, mixed>  $metadata
     * @return array<string, mixed>
     */
    private function withImpersonator(?Authenticatable $actor, array $metadata): array
    {
        $impersonatorId = $this->impersonation->get();

        if ($impersonatorId === null || (string) $actor?->getAuthIdentifier() === $impersonatorId) {
            return $metadata;
        }

        $name = User::query()->find($impersonatorId)?->fullName();

        return $metadata + ['impersonated_by' => $impersonatorId, 'impersonated_by_name' => $name];
    }
}
