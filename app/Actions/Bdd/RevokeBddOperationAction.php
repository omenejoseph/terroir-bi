<?php

declare(strict_types=1);

namespace App\Actions\Bdd;

use App\Models\BddOperationGrant;

/**
 * Remove an operation from the BDD allowlist. Compiled plans that reference it
 * keep their plan but park as NEEDS_ACCESS at the next run (the runner
 * re-checks grants every time) — nothing breaks loudly.
 */
class RevokeBddOperationAction
{
    public function execute(string $operationKey): void
    {
        BddOperationGrant::query()->where('operation_key', $operationKey)->delete();
    }
}
