<?php

declare(strict_types=1);

namespace App\Services\LegacyImport\Support;

use Illuminate\Database\Connection;

/** Lists every legacy table that has data but is not (or only partly) imported, with a reason. */
final class NotMigrated
{
    /** Legacy tables read by an import step. */
    public const HANDLED = [
        'User', 'PricingTier', 'InventoryItem', 'InventoryImage', 'RecipeItem', 'Customer', 'TierPrice', 'CustomerPrice',
        'CustomerProductOverride', 'Supplier', 'SupplierPriceItem', 'Order', 'OrderItem', 'OrderStatusHistory', 'OrderNote',
        'OrderNoteReaction', 'ConsignmentReport', 'ConsignmentReportItem', 'StockMovement', 'Cost', 'CostItem', 'Inflow',
        'EnologicalProduct', 'FermentationTemplate', 'Vessel', 'WineLot', 'WineLotGrape', 'VesselLot', 'CellarAnalysis',
        'CellarAddition', 'CellarProcess', 'CellarTransfer', 'TastingReport', 'CellarTastingNote', 'Board', 'WorkOrder',
        'ProductionPlan', 'ProductionPlanRow', 'VineyardParcel', 'PhenologyLog', 'CropEstimate', 'VineyardApplication',
        'GrapeContract', 'IntakeBooking', 'CustomerCategory',
    ];

    /** First matching prefix wins. */
    private const REASONS = [
        'Remaris' => 'POS sync (Remaris) — deferred by design',
        'Club' => 'wine club — no counterpart in the rebuild',
        'Employee' => 'HR — no counterpart in the rebuild',
        'Villa' => 'hospitality / villas — deferred by design',
        'Reservation' => 'hospitality reservations — deferred by design',
        'Hospitality' => 'hospitality — deferred by design',
        'Kitchen' => 'kitchen — no counterpart in the rebuild',
        'Deal' => 'CRM — no counterpart in the rebuild',
        'Contact' => 'CRM — no counterpart in the rebuild',
        'Sales' => 'CRM / sales projects — no counterpart in the rebuild',
        'Pipeline' => 'CRM — no counterpart in the rebuild',
        'LeadSource' => 'CRM — no counterpart in the rebuild',
        'Activity' => 'CRM — no counterpart in the rebuild',
        'Project' => 'sales projects — no counterpart in the rebuild',
        'Satisfaction' => 'surveys — no counterpart in the rebuild',
        'Survey' => 'surveys — no counterpart in the rebuild',
        'Cash' => 'cash-flow planner — no counterpart in the rebuild',
        'EInvoice' => 'e-invoice XML — no counterpart in the rebuild',
        'BankTransaction' => 'bank transactions — no counterpart in the rebuild',
        'InflowItem' => 'inflow line items — no counterpart in the rebuild',
        'InflowAttachment' => 'attachments — no counterpart in the rebuild',
        'CostAttachment' => 'attachments — no counterpart in the rebuild',
        'CellarActivity' => 'planned cellar activities — no counterpart in the rebuild',
        'Harvest' => 'harvest planning inputs — no counterpart in the rebuild',
        'ProductionFinalPlan' => 'final production plan — no counterpart in the rebuild',
        'Protocol' => 'protocol additives — no counterpart in the rebuild',
        'Notification' => 'in-app notifications — transient, not migrated',
        'PushSubscription' => 'push endpoints are bound to the old origin — users re-subscribe',
        'TranslationOverride' => 'translation overrides are platform-wide in the rebuild (Filament /admin)',
        'PlatformFlag' => 'platform flags — not tenant data',
        'BoardList' => 'board lists — status is the column in the rebuild',
        'BoardMember' => 'board membership — no counterpart in the rebuild',
        'InventoryGroupOrder' => 'inventory group ordering — no counterpart in the rebuild',
        'InventoryTechSheet' => 'tech sheets — files not migrated',
    ];

    /** @return array<string, array{rows: int, reason: string}> tables with rows only */
    public static function scan(Connection $legacy): array
    {
        // Driver-agnostic (Postgres in production, SQLite in tests); `public` is the Prisma schema.
        $tables = collect($legacy->getSchemaBuilder()->getTables())
            ->filter(fn (array $t) => in_array($t['schema'] ?? null, [null, '', 'public', 'main'], true))
            ->pluck('name')->sort()->values();

        $out = [];
        foreach ($tables as $table) {
            if (in_array($table, self::HANDLED, true)) {
                continue;
            }
            $rows = (int) $legacy->table($table)->count();
            if ($rows === 0) {
                continue;
            }
            $out[$table] = ['rows' => $rows, 'reason' => self::reason($table)];
        }

        return $out;
    }

    public static function reason(string $table): string
    {
        foreach (self::REASONS as $prefix => $reason) {
            if (str_starts_with($table, $prefix)) {
                return $reason;
            }
        }

        return 'no counterpart in the rebuild';
    }
}
