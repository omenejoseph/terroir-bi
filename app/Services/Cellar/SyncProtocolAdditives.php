<?php

declare(strict_types=1);

namespace App\Services\Cellar;

use App\Models\FermentationTemplate;
use App\Models\ProtocolAdditive;

/**
 * Replace a protocol's structured additive doses with the given list (the
 * editor saves the whole list at once). Rows keep their order as given.
 */
class SyncProtocolAdditives
{
    /**
     * @param  array<int, array<string, mixed>>  $additives  [{name, dose, basis, enological_product_id?, stage?, notes?}]
     */
    public function sync(FermentationTemplate $template, array $additives): void
    {
        $template->additives()->get()->each(fn (ProtocolAdditive $a) => $a->delete());

        foreach (array_values($additives) as $i => $row) {
            $template->additives()->create([
                'name' => $row['name'],
                'enological_product_id' => $row['enological_product_id'] ?? null,
                'dose' => (string) $row['dose'],
                'basis' => $row['basis'],
                'stage' => $row['stage'] ?? null,
                'notes' => $row['notes'] ?? null,
                'sort_order' => $i,
            ]);
        }

        $template->unsetRelation('additives');
    }
}
