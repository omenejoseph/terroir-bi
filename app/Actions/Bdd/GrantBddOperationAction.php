<?php

declare(strict_types=1);

namespace App\Actions\Bdd;

use App\Models\BddOperationGrant;
use App\Services\Bdd\OperationRegistry;

/**
 * Grant one operation to the BDD allowlist (fail-closed model: the maintainer
 * explicitly opens access). Blocklisted namespaces can never be granted —
 * the registry throws. No fan-out is needed: the next live run simply sees
 * the new grant in its catalog.
 */
class GrantBddOperationAction
{
    public function __construct(private readonly OperationRegistry $registry) {}

    public function execute(string $operationKey, ?string $grantedById = null, ?string $note = null): BddOperationGrant
    {
        $this->registry->assertGrantable($operationKey);

        return BddOperationGrant::query()->firstOrCreate(
            ['operation_key' => $operationKey],
            ['granted_by_id' => $grantedById, 'note' => $note],
        );
    }
}
