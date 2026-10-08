<?php

declare(strict_types=1);

namespace App\Services\LegacyImport;

use App\Models\Tenant;
use App\Services\Uploads\Contracts\ObjectStore;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * Copies the legacy Vercel Blob files behind imported inventory images into the
 * tenant's bucket namespace. `inventory_images.object_key` was already set by the
 * import (`tenants/<id>/inventory/<file>`); this moves the bytes there and fills in
 * the real size and content type.
 *
 * Idempotent: an image whose row has a size and whose object already exists is left
 * alone unless $force. Only inventory images carry blob files in the legacy data
 * (phenology photos and the other URL columns are empty).
 */
class MediaCopier
{
    private const MAX_BYTES = 5 * 1024 * 1024; // config('uploads.purposes.inventory_image.max_bytes')

    public function __construct(private readonly ObjectStore $store) {}

    /**
     * @return array{copied: int, skipped: int, failed: int, bytes: int, failures: list<string>, oversize: list<string>}
     */
    public function copy(Tenant $tenant, Connection $legacy, bool $dryRun = false, bool $force = false, ?int $limit = null): array
    {
        $tenantId = (string) $tenant->getKey();
        $urls = $legacy->table('InventoryImage')->pluck('url', 'id');

        $rows = DB::table('legacy_id_map as m')
            ->join('inventory_images as i', 'i.id', '=', 'm.new_id')
            ->where('m.tenant_id', $tenantId)->where('m.legacy_table', 'InventoryImage')
            ->orderBy('i.id')
            ->get(['i.id', 'i.object_key', 'i.size_bytes', 'm.legacy_id']);

        $out = ['copied' => 0, 'skipped' => 0, 'failed' => 0, 'bytes' => 0, 'failures' => [], 'oversize' => []];
        $done = 0;

        foreach ($rows as $row) {
            if ($limit !== null && $done >= $limit) {
                break;
            }

            if (! $force && (int) $row->size_bytes > 0 && $this->store->exists($row->object_key)) {
                $out['skipped']++;

                continue;
            }

            $url = $urls[$row->legacy_id] ?? null;
            if ($url === null) {
                $out['failed']++;
                $out['failures'][] = "{$row->object_key}: legacy image {$row->legacy_id} has no URL";

                continue;
            }

            $done++;

            try {
                $response = $dryRun ? Http::timeout(30)->head($url) : Http::timeout(60)->retry(2, 500, throw: false)->get($url);
            } catch (\Throwable $e) {
                $out['failed']++;
                $out['failures'][] = "{$url}: {$e->getMessage()}";

                continue;
            }

            if (! $response->successful()) {
                $out['failed']++;
                $out['failures'][] = "{$url}: HTTP {$response->status()}";

                continue;
            }

            $type = strtolower(trim(explode(';', (string) $response->header('Content-Type'))[0]));
            $type = str_starts_with($type, 'image/') ? $type : $this->typeFromKey($row->object_key);
            $size = $dryRun ? (int) $response->header('Content-Length') : strlen($response->body());

            if ($size > self::MAX_BYTES) {
                $out['oversize'][] = "{$row->object_key} ({$size} bytes)"; // copied anyway; flagged for the upload policy
            }

            if (! $dryRun) {
                $this->store->put($row->object_key, $response->body(), $type);
                DB::table('inventory_images')->where('id', $row->id)->update([
                    'size_bytes' => $size,
                    'content_type' => $type,
                    'updated_at' => now(),
                ]);
            }

            $out['copied']++;
            $out['bytes'] += $size;
        }

        return $out;
    }

    private function typeFromKey(string $key): string
    {
        return match (strtolower(pathinfo($key, PATHINFO_EXTENSION))) {
            'webp' => 'image/webp',
            'png' => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            'gif' => 'image/gif',
            default => 'application/octet-stream',
        };
    }
}
