<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Services\LegacyImport\IdMap;
use App\Services\LegacyImport\ImportContext;
use App\Services\LegacyImport\LegacyImporter;
use App\Services\LegacyImport\Report;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class ImportLegacyTenant extends Command
{
    protected $signature = 'legacy:import
        {--tenant=bibic-winery : Slug of the (already created) target tenant}
        {--dry-run : Run everything, then roll back}
        {--only= : Comma-separated step names}
        {--chunk=500 : Rows per read chunk}';

    protected $description = 'Import the legacy Prisma/Neon Bibich database (the `legacy` connection) into a tenant';

    public function handle(LegacyImporter $importer): int
    {
        $slug = (string) $this->option('tenant');
        $tenant = Tenant::query()->where('slug', $slug)->first();

        if ($tenant === null) {
            $this->error("Tenant [{$slug}] does not exist. Create it first with tenant:create.");

            return self::FAILURE;
        }

        $only = array_values(array_filter(array_map('trim', explode(',', (string) $this->option('only')))));
        $dryRun = (bool) $this->option('dry-run');

        $ctx = new ImportContext(
            tenant: $tenant,
            legacy: DB::connection('legacy'),
            ids: new IdMap((string) $tenant->getKey()),
            report: new Report,
            chunk: (int) $this->option('chunk'),
        );

        $report = $importer->run($ctx, $only, $dryRun);

        $this->table(
            ['Step', 'Read', 'Written', 'Skipped'],
            collect($report->counts())->map(fn ($c, $k) => [$k, $c['read'], $c['written'], $c['skipped']])->values()->all(),
        );
        if ($report->notMigrated() !== []) {
            $this->line('Not migrated (legacy tables with data and no destination):');
            $this->table(
                ['Legacy table', 'Rows', 'Reason'],
                collect($report->notMigrated())->map(fn ($v, $k) => [$k, $v['rows'], $v['reason']])->values()->all(),
            );
        }
        foreach ($report->warnings() as $w) {
            $this->warn($w);
        }

        $file = 'legacy-import/'.now()->format('Ymd-His').($dryRun ? '-dry' : '').'.json';
        Storage::disk('local')->put($file, json_encode($report->toArray(), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        $this->info(($dryRun ? 'Dry run (rolled back). ' : 'Imported. ').'Report: '.Storage::disk('local')->path($file));

        return self::SUCCESS;
    }
}
