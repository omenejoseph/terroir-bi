<?php

declare(strict_types=1);

namespace App\Services\LegacyImport;

use App\Services\LegacyImport\Steps\CellarStep;
use App\Services\LegacyImport\Steps\CostsStep;
use App\Services\LegacyImport\Steps\CustomerCategoriesStep;
use App\Services\LegacyImport\Steps\CustomersStep;
use App\Services\LegacyImport\Steps\InflowsStep;
use App\Services\LegacyImport\Steps\InventoryStep;
use App\Services\LegacyImport\Steps\OrdersStep;
use App\Services\LegacyImport\Steps\PricingTiersStep;
use App\Services\LegacyImport\Steps\ProductionStep;
use App\Services\LegacyImport\Steps\StockMovementsStep;
use App\Services\LegacyImport\Steps\SuppliersStep;
use App\Services\LegacyImport\Steps\UsersStep;
use App\Services\LegacyImport\Steps\VineyardsStep;
use App\Services\LegacyImport\Support\NotMigrated;
use App\Tenancy\Contracts\TenantContext;
use Illuminate\Support\Facades\DB;

/** Runs the ordered import steps for one tenant inside a single transaction. */
class LegacyImporter
{
    public function __construct(private readonly TenantContext $tenants) {}

    /** @return list<ImportStep> in dependency order */
    public function steps(): array
    {
        return [
            new UsersStep,
            new PricingTiersStep,
            new InventoryStep,
            new CustomersStep,
            new CustomerCategoriesStep,
            new SuppliersStep,
            new OrdersStep,
            new StockMovementsStep,
            new CostsStep,
            new InflowsStep,
            new CellarStep,
            new ProductionStep,
            new VineyardsStep,
        ];
    }

    /**
     * @param  list<string>  $only  step names (dependencies must already be mapped); empty = all
     */
    public function run(ImportContext $ctx, array $only = [], bool $dryRun = false): Report
    {
        $this->tenants->makeCurrent($ctx->tenant);

        try {
            DB::transaction(function () use ($ctx, $only, $dryRun): void {
                foreach ($this->steps() as $step) {
                    if ($only !== [] && ! in_array($step->name(), $only, true)) {
                        continue;
                    }
                    $step->run($ctx);
                }

                if ($dryRun) {
                    DB::rollBack();
                }
            });

            // Only meaningful for a full run; a partial --only run would list everything else.
            if ($only === []) {
                $ctx->report->setNotMigrated(NotMigrated::scan($ctx->legacy));
            }
        } finally {
            $this->tenants->forget();
        }

        return $ctx->report;
    }
}
