<?php

declare(strict_types=1);

namespace Tests\Concerns;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** An in-memory `legacy` connection with the Prisma tables the importer reads (loosely typed). */
trait LegacyFixture
{
    /** @var array<string, list<string>> legacy table => columns (all nullable strings in the fixture) */
    private const LEGACY_TABLES = [
        'User' => ['id', 'name', 'email', 'hashedPassword', 'role', 'createdAt', 'canEditOrders', 'canSeeShippedOrders'],
        'PricingTier' => ['id', 'name', 'description', 'rebatePercent', 'createdAt', 'updatedAt'],
        'InventoryItem' => ['id', 'name', 'sku', 'description', 'category', 'group', 'subcategory', 'vintage', 'unit', 'currentStock', 'minStock', 'isActive', 'createdAt', 'updatedAt', 'defaultPrice', 'bottlesPerCase', 'isForSale', 'costPerUnit', 'sortOrder', 'unitSize', 'salesUnit', 'packSize', 'hideFromPortal', 'autoCreatedAt', 'baseProductId', 'isAutoCreated'],
        'InventoryImage' => ['id', 'url', 'alt', 'sortOrder', 'inventoryItemId'],
        'RecipeItem' => ['id', 'quantity', 'outputId', 'inputId', 'customCost', 'customName', 'customUnit'],
        'Customer' => ['id', 'companyName', 'contactName', 'email', 'phone', 'address', 'city', 'state', 'zip', 'country', 'notes', 'isActive', 'rebatePercent', 'createdAt', 'updatedAt', 'pricingTierId', 'excludeFromStats', 'orderToken', 'hidePrices', 'oib', 'isAgency', 'allowSingleBottle', 'customerType', 'reorderContactedAt'],
        'CustomerCategory' => ['id', 'name', 'sortOrder', 'isActive', 'createdAt'],
        'TierPrice' => ['id', 'price', 'inventoryItemId', 'pricingTierId'],
        'CustomerPrice' => ['id', 'price', 'inventoryItemId', 'customerId'],
        'CustomerProductOverride' => ['id', 'customerId', 'inventoryItemId', 'visible'],
        'Supplier' => ['id', 'companyName', 'contactName', 'email', 'phone', 'address', 'city', 'country', 'taxId', 'bankAccount', 'paymentTerms', 'notes', 'isActive', 'createdAt', 'updatedAt', 'excludeFromStats', 'isCooperant', 'portalEnabled', 'portalToken'],
        'SupplierPriceItem' => ['id', 'description', 'unitPrice', 'unit', 'notes', 'lastUpdated', 'supplierId', 'inventoryItemId'],
        'Order' => ['id', 'orderNumber', 'status', 'totalAmount', 'notes', 'createdAt', 'updatedAt', 'customerId', 'createdById', 'lastStaleNotifiedAt', 'isBackorder', 'backorderDate', 'shippingCost', 'shippingPaidByUs', 'consignmentClosedAt', 'isConsignment'],
        'OrderItem' => ['id', 'quantity', 'unitType', 'unitPrice', 'total', 'orderId', 'inventoryItemId', 'costPerUnit', 'customDescription'],
        'OrderStatusHistory' => ['id', 'status', 'note', 'createdAt', 'orderId', 'changedById'],
        'OrderNote' => ['id', 'content', 'createdAt', 'orderId', 'authorId'],
        'OrderNoteReaction' => ['id', 'emoji', 'createdAt', 'noteId', 'userId'],
        'ConsignmentReport' => ['id', 'orderId', 'kind', 'date', 'note', 'createdById', 'createdAt'],
        'ConsignmentReportItem' => ['id', 'reportId', 'orderItemId', 'inventoryItemId', 'quantity', 'unitPrice', 'total'],
        'EnologicalProduct' => ['id', 'name', 'category', 'unit', 'currentStock', 'minStock', 'costPerUnit', 'manufacturer', 'notes', 'isActive', 'createdAt', 'updatedAt', 'packagingSize', 'supplierId', 'supplierPriceItemId', 'so2UpliftPerUnit'],
        'FermentationTemplate' => ['id', 'name', 'wineType', 'yeastStrain', 'targetTempMin', 'targetTempMax', 'punchdownSchedule', 'maceration', 'nutrients', 'mlf', 'notes', 'isActive', 'createdAt', 'updatedAt', 'description', 'estimatedDuration', 'stages'],
        'Vessel' => ['id', 'name', 'type', 'material', 'capacityLiters', 'currentVolume', 'location', 'status', 'notes', 'isActive', 'createdAt', 'updatedAt', 'mapHeight', 'mapWidth', 'positionX', 'positionY', 'room', 'rotation', 'faultNote', 'isFaulty'],
        'WineLot' => ['id', 'lotNumber', 'name', 'grapeVariety', 'vintage', 'vineyard', 'initialVolume', 'currentVolume', 'status', 'grapeCost', 'notes', 'createdAt', 'updatedAt', 'grapePricePerKg', 'harvestWeightKg', 'wineType', 'fermentationTemplateId'],
        'WineLotGrape' => ['id', 'wineLotId', 'grapeVariety', 'percentage', 'pricePerKg', 'weightKg', 'harvestEntryId', 'createdAt'],
        'VesselLot' => ['id', 'volume', 'addedAt', 'vesselId', 'wineLotId'],
        'CellarAnalysis' => ['id', 'date', 'pH', 'totalAcidity', 'volatileAcidity', 'alcohol', 'residualSugar', 'freeSO2', 'totalSO2', 'brix', 'temperature', 'density', 'note', 'createdAt', 'wineLotId', 'createdById', 'glucoseFructose', 'lactic', 'malic', 'tpi', 'vesselId'],
        'CellarAddition' => ['id', 'name', 'category', 'quantity', 'unit', 'costPerUnit', 'totalCost', 'note', 'createdAt', 'wineLotId', 'createdById', 'enologicalProductId'],
        'CellarProcess' => ['id', 'date', 'kind', 'note', 'volume', 'vesselId', 'wineLotId', 'createdAt', 'createdById'],
        'CellarTransfer' => ['id', 'type', 'volumeLiters', 'note', 'createdAt', 'fromLotId', 'toLotId', 'fromVesselId', 'toVesselId', 'createdById'],
        'CellarTastingNote' => ['id', 'date', 'appearance', 'nose', 'palate', 'overall', 'score', 'note', 'createdAt', 'wineLotId', 'createdById', 'vesselId', 'tastingReportId'],
        'TastingReport' => ['id', 'title', 'date', 'note', 'createdById', 'createdAt'],
        'Board' => ['id', 'isDefault', 'sortOrder', 'createdAt', 'updatedAt', 'ownerId', 'name', 'description'],
        'WorkOrder' => ['id', 'title', 'description', 'category', 'priority', 'status', 'dueDate', 'completedAt', 'wineLotId', 'vesselId', 'assigneeId', 'createdById', 'createdAt', 'updatedAt', 'startDate', 'sortOrder', 'boardId', 'listId', 'listOrder'],
        'ProductionPlan' => ['id', 'name', 'status', 'notes', 'createdAt', 'updatedAt', 'confirmedAt', 'createdById'],
        'ProductionPlanRow' => ['id', 'planId', 'baseItemId', 'newVintage', 'createdItemId', 'quantity', 'planUnit', 'sortOrder'],
        'VineyardParcel' => ['id', 'name', 'grapeVariety', 'areaHectares', 'location', 'elevation', 'soilType', 'plantingYear', 'rowSpacing', 'vineCount', 'notes', 'isActive', 'createdAt', 'updatedAt', 'rootstock', 'training', 'geoAreaCalculated', 'geoPolygon', 'latitude', 'longitude', 'orientation', 'slope', 'weatherStationId', 'cooperantSupplierId', 'ownership', 'lastHarvestDate'],
        'PhenologyLog' => ['id', 'date', 'stage', 'progress', 'notes', 'photoUrl', 'createdAt', 'parcelId'],
        'CropEstimate' => ['id', 'date', 'season', 'clusterCount', 'avgClusterWeight', 'sampleVineCount', 'estimatedYieldKg', 'notes', 'createdAt', 'parcelId'],
        'VineyardApplication' => ['id', 'date', 'type', 'product', 'dosage', 'dosageUnit', 'phiDays', 'phiEndDate', 'weather', 'notes', 'createdAt', 'parcelId'],
        'IntakeBooking' => ['id', 'date', 'timeSlot', 'grapeVariety', 'estimatedKg', 'growerName', 'status', 'notes', 'createdAt', 'updatedAt', 'harvestPlanId', 'supplierId'],
        'GrapeContract' => ['id', 'season', 'status', 'grapeVariety', 'estimatedKg', 'deliveredKg', 'pricePerKg', 'minBrix', 'maxPH', 'deliveryWindow', 'paymentTerms', 'notes', 'createdAt', 'updatedAt', 'supplierId', 'parcelId'],
        'Reservation' => ['id', 'guestName'],
        'StockMovement' => ['id', 'type', 'quantity', 'unit', 'note', 'reference', 'createdAt', 'inventoryItemId', 'createdById', 'isReconciliation'],
        'EInvoice' => ['id', 'costId'],
        'Cost' => ['id', 'date', 'totalAmount', 'currency', 'category', 'description', 'reference', 'status', 'paymentMethod', 'notes', 'createdAt', 'updatedAt', 'supplierId', 'createdById', 'bankTransactionId', 'paidAt', 'vatAmount', 'dueDate', 'isLegacy'],
        'CostItem' => ['id', 'description', 'quantity', 'unitPrice', 'total', 'category', 'costId', 'inventoryItemId', 'wineLotId'],
        'Inflow' => ['id', 'date', 'totalAmount', 'vatAmount', 'currency', 'category', 'description', 'reference', 'status', 'receivedAt', 'paymentMethod', 'notes', 'createdAt', 'updatedAt', 'customerId', 'createdById', 'bankTransactionId', 'eInvoiceId', 'orderId', 'dueDate', 'isCreditNote', 'cancelledByInflowId', 'isCancelled', 'type', 'isLegacy'],
    ];

    protected function createLegacySchema(): void
    {
        config(['database.connections.legacy' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
        DB::purge('legacy');

        foreach (self::LEGACY_TABLES as $table => $columns) {
            Schema::connection('legacy')->create($table, function ($t) use ($columns): void {
                foreach ($columns as $c) {
                    $t->string($c)->nullable();
                }
            });
        }
    }

    /** @param array<string, mixed> $o */
    protected function legacyInsert(string $table, array $o): void
    {
        DB::connection('legacy')->table($table)->insert($o + array_fill_keys(self::LEGACY_TABLES[$table], null));
    }
}
