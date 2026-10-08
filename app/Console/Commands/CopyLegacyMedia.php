<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Services\LegacyImport\MediaCopier;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class CopyLegacyMedia extends Command
{
    protected $signature = 'legacy:copy-media
        {--tenant=bibic-winery : Slug of the tenant that was imported}
        {--dry-run : Only check that every source file is reachable; write nothing}
        {--force : Re-copy images that already have an object}
        {--limit= : Stop after N files (for a trial run)}';

    protected $description = 'Copy legacy Vercel Blob inventory images into the tenant\'s bucket namespace';

    public function handle(MediaCopier $copier): int
    {
        $tenant = Tenant::query()->where('slug', (string) $this->option('tenant'))->first();
        if ($tenant === null) {
            $this->error('Tenant ['.$this->option('tenant').'] does not exist.');

            return self::FAILURE;
        }

        $limit = $this->option('limit') === null ? null : (int) $this->option('limit');
        $result = $copier->copy($tenant, DB::connection('legacy'), (bool) $this->option('dry-run'), (bool) $this->option('force'), $limit);

        $this->table(['Copied', 'Already there', 'Failed', 'Bytes'], [[$result['copied'], $result['skipped'], $result['failed'], number_format($result['bytes'])]]);
        foreach ($result['oversize'] as $o) {
            $this->warn("Over the 5 MB upload policy (copied anyway): {$o}");
        }
        foreach ($result['failures'] as $f) {
            $this->error($f);
        }
        $this->info($this->option('dry-run') ? 'Dry run: nothing was written.' : 'Done. Re-run to retry any failures.');

        return $result['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
