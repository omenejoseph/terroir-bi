<?php

declare(strict_types=1);

namespace App\Services\LegacyImport\Steps;

use App\Services\LegacyImport\ImportContext;
use App\Services\LegacyImport\ImportStep;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\LazyCollection;
use Illuminate\Support\Str;
use stdClass;

/**
 * Shared plumbing for steps. Rows are written with the query builder (not
 * Eloquent/Actions) so legacy numbers, totals and timestamps survive untouched
 * and no audit/notification side effects fire. Writes are upserts keyed by the
 * id-map, so re-running a step updates in place instead of duplicating.
 */
abstract class AbstractStep implements ImportStep
{
    public function dependsOn(): array
    {
        return [];
    }

    /** @return LazyCollection<int, stdClass> */
    protected function rows(ImportContext $ctx, string $legacyTable): LazyCollection
    {
        return $ctx->legacy->table($legacyTable)->orderBy('id')->lazyById($ctx->chunk);
    }

    /**
     * Insert-or-update one mapped row and record its id. $attrs must not include
     * id / tenant_id (added here).
     *
     * @param  array<string, mixed>  $attrs
     */
    protected function put(ImportContext $ctx, string $legacyTable, string $legacyId, string $target, array $attrs): string
    {
        $id = $ctx->ids->get($legacyTable, $legacyId) ?? (string) Str::ulid();
        $row = ['id' => $id, 'tenant_id' => (string) $ctx->tenant->getKey()] + $attrs;

        DB::table($target)->upsert([$row], ['id'], array_values(array_diff(array_keys($row), ['id', 'tenant_id'])));
        $ctx->ids->put($legacyTable, $legacyId, $id);

        return $id;
    }

    /** Truncate to a varchar(N) column's limit, reporting what was cut (nothing is silently lost). */
    protected function fit(ImportContext $ctx, string $step, string $what, ?string $value, int $max = 255): ?string
    {
        if ($value === null || mb_strlen($value) <= $max) {
            return $value;
        }

        $ctx->report->warn($step, "{$what} truncated from ".mb_strlen($value)." to {$max} chars: \"".mb_substr($value, 0, 40).'…"');

        return mb_substr($value, 0, $max);
    }
}
