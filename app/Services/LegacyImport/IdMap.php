<?php

declare(strict_types=1);

namespace App\Services\LegacyImport;

use Illuminate\Support\Facades\DB;

/** Persistent legacy-id → new-id map, scoped per tenant and legacy table. */
class IdMap
{
    /** @var array<string, string> */
    private array $cache = [];

    public function __construct(private readonly string $tenantId) {}

    public function put(string $table, string $legacyId, string $newId): void
    {
        DB::table('legacy_id_map')->upsert(
            [[
                'tenant_id' => $this->tenantId,
                'legacy_table' => $table,
                'legacy_id' => $legacyId,
                'new_id' => $newId,
                'created_at' => now(),
                'updated_at' => now(),
            ]],
            ['tenant_id', 'legacy_table', 'legacy_id'],
            ['new_id', 'updated_at'],
        );
        $this->cache["$table:$legacyId"] = $newId;
    }

    public function get(string $table, ?string $legacyId): ?string
    {
        if ($legacyId === null || $legacyId === '') {
            return null;
        }

        $key = "$table:$legacyId";

        return $this->cache[$key] ??= DB::table('legacy_id_map')
            ->where('tenant_id', $this->tenantId)
            ->where('legacy_table', $table)
            ->where('legacy_id', $legacyId)
            ->value('new_id');
    }

    public function forget(): void
    {
        DB::table('legacy_id_map')->where('tenant_id', $this->tenantId)->delete();
        $this->cache = [];
    }
}
