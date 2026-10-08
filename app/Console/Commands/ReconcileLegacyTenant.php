<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Services\LegacyImport\Reconciler;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class ReconcileLegacyTenant extends Command
{
    protected $signature = 'legacy:reconcile
        {--tenant=bibic-winery : Slug of the imported tenant}
        {--only-problems : Print only FAIL rows (and INFO facts)}';

    protected $description = 'Compare the legacy database with the imported tenant (counts and totals); exits 1 on any mismatch';

    public function handle(Reconciler $reconciler): int
    {
        $tenant = Tenant::query()->where('slug', (string) $this->option('tenant'))->first();
        if ($tenant === null) {
            $this->error('Tenant ['.$this->option('tenant').'] does not exist.');

            return self::FAILURE;
        }

        $rows = $reconciler->run($tenant, DB::connection('legacy'));
        $fail = count(array_filter($rows, fn ($r) => $r['status'] === 'FAIL'));
        $pass = count(array_filter($rows, fn ($r) => $r['status'] === 'PASS'));

        $shown = $this->option('only-problems') ? array_values(array_filter($rows, fn ($r) => $r['status'] !== 'PASS')) : $rows;
        $this->table(['Area', 'Check', 'Legacy', 'New', '', 'Note'], array_map(fn ($r) => [
            $r['area'], $r['check'], $r['legacy'], $r['new'], $r['status'] === 'FAIL' ? '<fg=red>FAIL</>' : ($r['status'] === 'PASS' ? '<fg=green>ok</>' : 'info'), $r['note'],
        ], $shown));

        $file = 'legacy-import/reconcile-'.now()->format('Ymd-His').'.json';
        Storage::disk('local')->put($file, json_encode(['tenant' => $tenant->slug, 'pass' => $pass, 'fail' => $fail, 'checks' => $rows], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        $path = Storage::disk('local')->path($file);

        $fail === 0 ? $this->info("{$pass} checks passed, 0 failed. Report: {$path}") : $this->error("{$fail} checks FAILED, {$pass} passed. Report: {$path}");

        return $fail === 0 ? self::SUCCESS : self::FAILURE;
    }
}
