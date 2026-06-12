<?php

declare(strict_types=1);

namespace App\Actions\Bdd;

use App\Enums\BddScenarioStatus;
use App\Jobs\CompileBddScenarioJob;
use App\Models\BddScenario;
use Illuminate\Support\Str;

/**
 * Create or update a BDD scenario. Any change to the Gherkin invalidates the
 * compiled plan and queues a fresh compile (the AI binds steps to granted
 * operations; missing grants park the scenario in NEEDS_ACCESS).
 */
class SaveBddScenarioAction
{
    /**
     * @param  array{title: string, gherkin: string, is_active?: bool}  $data
     */
    public function execute(array $data, ?string $userId = null, ?BddScenario $scenario = null): BddScenario
    {
        $gherkinChanged = $scenario === null || $scenario->gherkin !== $data['gherkin'];

        if ($scenario === null) {
            $scenario = BddScenario::create([
                'title' => $data['title'],
                'slug' => Str::slug($data['title']).'-'.strtolower(Str::random(6)),
                'gherkin' => $data['gherkin'],
                'status' => BddScenarioStatus::Draft,
                'is_active' => $data['is_active'] ?? true,
                'created_by_id' => $userId,
            ]);
        } else {
            $scenario->update([
                'title' => $data['title'],
                'gherkin' => $data['gherkin'],
                'is_active' => $data['is_active'] ?? $scenario->is_active,
                ...($gherkinChanged ? [
                    'status' => BddScenarioStatus::Draft,
                    'compiled_plan' => null,
                    'requested_operations' => null,
                    'compile_error' => null,
                ] : []),
            ]);
        }

        if ($gherkinChanged) {
            CompileBddScenarioJob::dispatch($scenario->getKey());
        }

        return $scenario;
    }
}
