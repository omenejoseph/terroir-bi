<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\AiImport;
use App\Models\Tenant;
use App\Services\Ai\ExtractionService;
use App\Tenancy\Contracts\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Extracts an uploaded document asynchronously. The tenant is rebound for the
 * duration (jobs start with no tenant context) so the tenant-scoped reads/writes
 * inside ExtractionService resolve correctly.
 */
class ProcessAiImportJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $timeout = 300;

    public int $tries = 1;

    public function __construct(
        public readonly string $importId,
        public readonly string $tenantId,
    ) {}

    public function handle(ExtractionService $service, TenantContext $context): void
    {
        $tenant = Tenant::find($this->tenantId);

        if ($tenant === null) {
            return;
        }

        $context->runFor($tenant, function () use ($service): void {
            $import = AiImport::find($this->importId);

            if ($import !== null) {
                $service->process($import);
            }
        });
    }
}
