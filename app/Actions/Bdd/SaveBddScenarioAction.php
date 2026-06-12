<?php

declare(strict_types=1);

namespace App\Actions\Bdd;

use App\Enums\BddScenarioStatus;
use App\Models\BddScenario;
use Illuminate\Support\Str;

/**
 * Create or update a BDD scenario. There is no compile step anymore — the
 * Gherkin is executed live by an AI agent on each run — so a saved scenario is
 * immediately READY.
 */
class SaveBddScenarioAction
{
    /**
     * @param  array{title: string, gherkin: string, is_active?: bool}  $data
     */
    public function execute(array $data, ?string $userId = null, ?BddScenario $scenario = null): BddScenario
    {
        if ($scenario === null) {
            return BddScenario::create([
                'title' => $data['title'],
                'slug' => Str::slug($data['title']).'-'.strtolower(Str::random(6)),
                'gherkin' => $data['gherkin'],
                'status' => BddScenarioStatus::Ready,
                'is_active' => $data['is_active'] ?? true,
                'created_by_id' => $userId,
            ]);
        }

        $scenario->update([
            'title' => $data['title'],
            'gherkin' => $data['gherkin'],
            'is_active' => $data['is_active'] ?? $scenario->is_active,
            'status' => BddScenarioStatus::Ready,
        ]);

        return $scenario;
    }
}
