<?php

declare(strict_types=1);

namespace App\Actions\Bdd;

use App\Enums\BddScenarioStatus;
use App\Jobs\CompileBddScenarioJob;
use App\Models\BddOperationGrant;
use App\Models\BddScenario;
use App\Services\Bdd\OperationRegistry;

/**
 * Grant one operation to the BDD allowlist (fail-closed model: the maintainer
 * explicitly opens access). Blocklisted namespaces can never be granted —
 * the registry throws. Scenarios parked in NEEDS_ACCESS that requested this
 * operation are automatically queued for recompilation.
 */
class GrantBddOperationAction
{
    public function __construct(private readonly OperationRegistry $registry) {}

    public function execute(string $operationKey, ?string $grantedById = null, ?string $note = null): BddOperationGrant
    {
        $this->registry->assertGrantable($operationKey);

        $grant = BddOperationGrant::query()->firstOrCreate(
            ['operation_key' => $operationKey],
            ['granted_by_id' => $grantedById, 'note' => $note],
        );

        // Recompile every scenario that was waiting on this operation.
        BddScenario::query()
            ->where('status', BddScenarioStatus::NeedsAccess->value)
            ->get()
            ->filter(fn (BddScenario $scenario): bool => collect($scenario->requested_operations ?? [])
                ->contains(fn (array $entry): bool => ($entry['suggested_operation'] ?? null) === $operationKey))
            ->each(fn (BddScenario $scenario) => CompileBddScenarioJob::dispatch($scenario->getKey()));

        return $grant;
    }
}
