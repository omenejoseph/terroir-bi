<?php

declare(strict_types=1);

namespace App\Services\Bdd;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * The sandbox a scenario run lives in: the throwaway tenant + the sandbox admin
 * acting as "the operator". Guard rail: every model an operation receives must
 * belong to this sandbox — entities can only enter a plan via $captures, and
 * this verifies a capture wasn't somehow swapped for another tenant's record.
 */
final class SandboxContext
{
    public function __construct(
        public readonly Tenant $tenant,
        public readonly User $admin,
    ) {}

    /** Assert a model resolved into an operation argument belongs to the sandbox. */
    public function assertOwned(Model $model): void
    {
        // Tenant-scoped rows must carry the sandbox tenant id.
        $tenantId = $model->getAttribute('tenant_id');
        if ($tenantId !== null) {
            if ((string) $tenantId !== (string) $this->tenant->getKey()) {
                throw new RuntimeException(
                    'Guard rail: a step referenced a record outside the sandbox tenant ('.$model::class.').',
                );
            }

            return;
        }

        // Central models: only the sandbox's own user rows (created by seeds)
        // may flow through a plan; everything else is off-limits.
        if ($model instanceof User) {
            $isSandboxUser = $model->is($this->admin)
                || $model->memberships()->where('tenant_id', $this->tenant->getKey())->exists();

            if (! $isSandboxUser) {
                throw new RuntimeException('Guard rail: a step referenced a user outside the sandbox tenant.');
            }

            return;
        }

        if ($model instanceof Tenant) {
            if (! $model->is($this->tenant)) {
                throw new RuntimeException('Guard rail: a step referenced a tenant other than the sandbox.');
            }

            return;
        }

        // PricingTier and other central catalog rows created inside the run are
        // fine — they roll back with everything else. Anything not covered above
        // and not tenant-scoped is allowed only because it can only have come
        // from a capture created within this run.
    }
}
