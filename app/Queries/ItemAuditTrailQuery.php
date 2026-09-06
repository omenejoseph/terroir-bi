<?php

declare(strict_types=1);

namespace App\Queries;

use App\Models\AuditLog;
use App\Models\InventoryItem;

/**
 * An item's audit trail (Figma 378:1592's "Timeline" section, and the
 * Provenance line's "last edited by"): every create/update/stock-adjustment
 * App\Services\Audit\Auditable (and App\Services\Inventory\StockLedger)
 * recorded for it, newest first.
 *
 * Reads the same `audit_logs` table as Web\LogController / Web\Admin\
 * AuditLogController, filtered to this one subject rather than a whole
 * tenant's feed — no new logging to add, this is read-side only.
 */
class ItemAuditTrailQuery
{
    /**
     * @return list<array<string, mixed>>
     */
    public function get(InventoryItem $item, int $limit = 20): array
    {
        $entries = AuditLog::query()
            ->where('subject_type', $item->getMorphClass())
            ->where('subject_id', $item->getKey())
            ->with('actor')
            // Ties on created_at (two entries in the same request/second) break
            // on id — ULIDs sort by creation, so this stays chronological
            // regardless of timestamp precision (see ItemMovementsQuery).
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();

        return array_values($entries->map(fn (AuditLog $log): array => [
            'id' => $log->getKey(),
            'action' => $log->action,
            'actor_name' => $log->actor?->fullName(),
            'metadata' => $log->metadata,
            'created_at' => $log->created_at?->toIso8601String(),
        ])->all());
    }
}
