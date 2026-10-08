<?php

declare(strict_types=1);

namespace App\Services\LegacyImport\Steps;

use App\Services\LegacyImport\ImportContext;
use App\Services\LegacyImport\Support\Normalize;

class PricingTiersStep extends AbstractStep
{
    public function name(): string
    {
        return 'pricing_tiers';
    }

    public function run(ImportContext $ctx): void
    {
        foreach ($this->rows($ctx, 'PricingTier') as $r) {
            $ctx->report->read('pricing_tiers');
            $this->put($ctx, 'PricingTier', (string) $r->id, 'pricing_tiers', [
                'name' => trim((string) $r->name),
                'description' => Normalize::str($r->description),
                'rebate_percent' => Normalize::dec($r->rebatePercent, 2),
                'created_at' => Normalize::date($r->createdAt),
                'updated_at' => Normalize::date($r->updatedAt),
            ]);
            $ctx->report->written('pricing_tiers');
        }
    }
}
