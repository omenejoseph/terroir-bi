<?php

use App\Http\Controllers\Api\AiImportController;
use App\Http\Controllers\Api\Auth\AuthController;
use App\Http\Controllers\Api\Auth\TenantSessionController;
use App\Http\Controllers\Api\Billing\StripeWebhookController;
use App\Http\Controllers\Api\BottleAnalysisController;
use App\Http\Controllers\Api\CashFlowController;
use App\Http\Controllers\Api\CellarController;
use App\Http\Controllers\Api\ConsignmentController;
use App\Http\Controllers\Api\CostController;
use App\Http\Controllers\Api\CustomerConsignmentController;
use App\Http\Controllers\Api\CustomerController;
use App\Http\Controllers\Api\CustomerProductOverrideController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\EnologicalProductController;
use App\Http\Controllers\Api\FermentationMonitorController;
use App\Http\Controllers\Api\FermentationTemplateController;
use App\Http\Controllers\Api\GrapeContractController;
use App\Http\Controllers\Api\HarvestPlanController;
use App\Http\Controllers\Api\InflowController;
use App\Http\Controllers\Api\IntakeBookingController;
use App\Http\Controllers\Api\InventoryCheckController;
use App\Http\Controllers\Api\InventoryItemController;
use App\Http\Controllers\Api\InventoryMediaController;
use App\Http\Controllers\Api\InventorySpendController;
use App\Http\Controllers\Api\InvitationController;
use App\Http\Controllers\Api\MemberController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\OrderCommentController;
use App\Http\Controllers\Api\OrderController;
use App\Http\Controllers\Api\OrderPaymentController;
use App\Http\Controllers\Api\PriceController;
use App\Http\Controllers\Api\PricingTierController;
use App\Http\Controllers\Api\ProductionPlanController;
use App\Http\Controllers\Api\PublicOrderController;
use App\Http\Controllers\Api\PublicSupplierController;
use App\Http\Controllers\Api\PushSubscriptionController;
use App\Http\Controllers\Api\SettingsController;
use App\Http\Controllers\Api\StockController;
use App\Http\Controllers\Api\SupplierController;
use App\Http\Controllers\Api\SupplierOrderController;
use App\Http\Controllers\Api\TastingReportController;
use App\Http\Controllers\Api\TranslationController;
use App\Http\Controllers\Api\UploadController;
use App\Http\Controllers\Api\VesselController;
use App\Http\Controllers\Api\VineyardParcelController;
use App\Http\Controllers\Api\WineLotController;
use App\Http\Controllers\Api\WorkOrderController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API routes (v1)
|--------------------------------------------------------------------------
*/

Route::prefix('v1')->group(function () {
    // Public — no authentication. The order token authenticates and selects the tenant.
    Route::post('auth/login', [AuthController::class, 'login']);
    Route::post('auth/invitations/accept', [InvitationController::class, 'accept']);
    Route::get('public/{token}/catalog', [PublicOrderController::class, 'catalog']);
    Route::post('public/{token}/orders', [PublicOrderController::class, 'store']);

    // Stripe webhooks — authenticated by signature, no tenant context.
    Route::post('stripe/webhook', [StripeWebhookController::class, 'handle']);

    // Public supplier portal — the portal token authenticates and selects the tenant.
    Route::get('public/supplier/{token}', [PublicSupplierController::class, 'show']);
    Route::post('public/supplier/{token}/price-items/import', [PublicSupplierController::class, 'importPriceItems']);
    Route::patch('public/supplier/{token}/orders/{order}/confirm', [PublicSupplierController::class, 'confirmOrder']);

    // Authenticated, but no active tenant required (e.g. to switch tenants).
    Route::middleware('auth:sanctum')->group(function () {
        Route::post('auth/logout', [AuthController::class, 'logout']);
        Route::get('auth/tenants', [TenantSessionController::class, 'index']);
        Route::post('auth/switch-tenant', [TenantSessionController::class, 'switch']);
    });

    // Authenticated + active tenant + verified membership.
    Route::middleware('tenant')->group(function () {
        Route::get('auth/me', [AuthController::class, 'me']);

        // Dashboard summary (any member of the active tenant).
        Route::get('dashboard', [DashboardController::class, 'summary']);

        // Organisation settings — read for any member; writes require admin.
        Route::get('settings', [SettingsController::class, 'show']);
        Route::patch('settings', [SettingsController::class, 'update'])->middleware('can:settings.manage');

        // Localization — read the global overrides; editing lives in the back office.
        Route::get('translations', [TranslationController::class, 'index']);

        // Member management (admin).
        Route::get('members', [MemberController::class, 'index'])->middleware('can:members.view');
        Route::middleware('can:members.manage')->group(function () {
            Route::patch('members/{user}', [MemberController::class, 'update']);
            Route::delete('members/{user}', [MemberController::class, 'destroy']);
        });

        // Invitations (admin).
        Route::middleware('can:invitations.manage')->group(function () {
            Route::get('invitations', [InvitationController::class, 'index']);
            Route::post('invitations', [InvitationController::class, 'store']);
            Route::delete('invitations/{invitation}', [InvitationController::class, 'destroy']);
        });

        // Inventory & recipes.
        Route::middleware('can:inventory.view')->group(function () {
            // Static segments must precede the {item} wildcard so they aren't treated as ids.
            Route::get('inventory-items/taxonomy', [InventoryItemController::class, 'taxonomy']);
            Route::get('inventory-items/analytics', [InventoryItemController::class, 'analytics']);
            Route::get('inventory-items/spend', [InventorySpendController::class, 'index']);
            Route::get('inventory-items', [InventoryItemController::class, 'index']);
            Route::get('inventory-items/{item}', [InventoryItemController::class, 'show']);
            Route::get('inventory-items/{item}/movements', [StockController::class, 'movements']);
            Route::get('inventory-items/{item}/stock-analytics', [StockController::class, 'stockAnalytics']);
            Route::get('inventory-items/{item}/recipe', [StockController::class, 'recipe']);
            Route::get('inventory-items/{item}/images', [InventoryMediaController::class, 'listImages']);
            Route::get('inventory-items/{item}/tech-sheets', [InventoryMediaController::class, 'listTechSheets']);
            Route::get('inventory-items/{item}/documents', [InventoryMediaController::class, 'listDocuments']);
            Route::get('inventory-items/{item}/bottle-analyses', [BottleAnalysisController::class, 'index']);
            Route::get('inventory-checks', [InventoryCheckController::class, 'index']);
            Route::get('inventory-checks/{check}', [InventoryCheckController::class, 'show']);
        });
        Route::middleware('can:inventory.manage')->group(function () {
            // Static segment before the {item} wildcard so it isn't treated as an id.
            Route::post('inventory-items/check', [StockController::class, 'check']);
            Route::post('inventory-items', [InventoryItemController::class, 'store']);
            Route::patch('inventory-items/{item}', [InventoryItemController::class, 'update']);
            Route::post('inventory-items/{item}/stock', [StockController::class, 'adjust']);
            Route::post('inventory-items/{item}/produce', [StockController::class, 'produce']);
            Route::put('inventory-items/{item}/recipe', [StockController::class, 'setRecipe']);
            Route::post('inventory-items/{item}/images', [InventoryMediaController::class, 'attachImage']);
            Route::delete('inventory-items/{item}/images/{image}', [InventoryMediaController::class, 'deleteImage']);
            Route::post('inventory-items/{item}/tech-sheets', [InventoryMediaController::class, 'attachTechSheet']);
            Route::delete('inventory-items/{item}/tech-sheets/{techSheet}', [InventoryMediaController::class, 'deleteTechSheet']);
            Route::post('inventory-items/{item}/documents', [InventoryMediaController::class, 'attachDocument']);
            Route::delete('inventory-items/{item}/documents/{document}', [InventoryMediaController::class, 'deleteDocument']);
            Route::post('inventory-items/{item}/bottle-analyses', [BottleAnalysisController::class, 'store']);
            Route::delete('inventory-items/{item}/bottle-analyses/{analysis}', [BottleAnalysisController::class, 'destroy']);
            Route::patch('stock-movements/{stockMovement}/reconciliation', [StockController::class, 'setReconciliation']);
        });
        Route::delete('inventory-items/{item}', [InventoryItemController::class, 'destroy'])
            ->middleware('can:inventory.delete');

        // Customers.
        Route::middleware('can:customers.view')->group(function () {
            // Static segments before the {customer} wildcard so they aren't treated as ids.
            Route::get('customers/lookup-vat', [CustomerController::class, 'lookupVat']);
            Route::get('customers/reorder-radar', [CustomerController::class, 'reorderRadar']);
            Route::get('customers/analytics', [CustomerController::class, 'analytics'])->middleware('can:financials.view');
            Route::get('customers', [CustomerController::class, 'index']);
            Route::get('customers/{customer}', [CustomerController::class, 'show']);
            Route::get('customers/{customer}/insights', [CustomerController::class, 'insights'])->middleware('can:financials.view');
            Route::get('customers/{customer}/order-analytics', [CustomerController::class, 'orderAnalytics'])->middleware('can:financials.view');
            Route::get('customers/{customer}/resolved-prices', [PriceController::class, 'resolvedPrices']);
            Route::get('customers/{customer}/custom-prices', [PriceController::class, 'customerPrices']);
            Route::get('customers/{customer}/product-overrides', [CustomerProductOverrideController::class, 'index']);
        });
        Route::middleware('can:customers.manage')->group(function () {
            Route::post('customers', [CustomerController::class, 'store']);
            Route::post('customers/quick', [CustomerController::class, 'quickStore']);
            Route::post('customers/merge/preview', [CustomerController::class, 'mergePreview']);
            Route::patch('customers/{customer}', [CustomerController::class, 'update']);
            Route::post('customers/{customer}/contacted', [CustomerController::class, 'markContacted']);
            Route::put('customers/{customer}/product-overrides/{item}', [CustomerProductOverrideController::class, 'upsert']);
            Route::delete('customers/{customer}/product-overrides/{item}', [CustomerProductOverrideController::class, 'destroy']);
        });
        Route::post('customers/merge', [CustomerController::class, 'merge'])
            ->middleware('can:customers.delete');
        Route::delete('customers/{customer}', [CustomerController::class, 'destroy'])
            ->middleware('can:customers.delete');
        Route::middleware('can:customers.tokens')->group(function () {
            Route::get('customers/{customer}/order-token', [CustomerController::class, 'showToken']);
            Route::post('customers/{customer}/order-token', [CustomerController::class, 'generateToken']);
            Route::delete('customers/{customer}/order-token', [CustomerController::class, 'revokeToken']);
        });

        // Suppliers + price lists.
        Route::middleware('can:suppliers.view')->group(function () {
            Route::get('supplier-orders', [SupplierOrderController::class, 'index']);
            Route::get('supplier-orders/{supplierOrder}', [SupplierOrderController::class, 'show']);
            Route::get('suppliers', [SupplierController::class, 'index']);
            Route::get('suppliers/{supplier}', [SupplierController::class, 'show']);
            Route::get('suppliers/{supplier}/stats', [SupplierController::class, 'stats']);
            Route::get('suppliers/{supplier}/price-changes', [SupplierController::class, 'priceChanges']);
        });
        Route::middleware('can:suppliers.manage')->group(function () {
            Route::post('suppliers/merge/preview', [SupplierController::class, 'mergePreview']);
            Route::post('suppliers', [SupplierController::class, 'store']);
            Route::patch('suppliers/{supplier}', [SupplierController::class, 'update']);
            Route::get('suppliers/{supplier}/portal-token', [SupplierController::class, 'showToken']);
            Route::post('suppliers/{supplier}/portal-token', [SupplierController::class, 'generateToken']);
            Route::delete('suppliers/{supplier}/portal-token', [SupplierController::class, 'revokeToken']);
            Route::post('suppliers/{supplier}/price-items', [SupplierController::class, 'addPriceItem']);
            Route::patch('suppliers/{supplier}/price-items/{priceItem}', [SupplierController::class, 'updatePriceItem']);
            Route::delete('suppliers/{supplier}/price-items/{priceItem}', [SupplierController::class, 'deletePriceItem']);
            Route::post('supplier-orders', [SupplierOrderController::class, 'store']);
            Route::patch('supplier-orders/{supplierOrder}/status', [SupplierOrderController::class, 'updateStatus']);
            Route::delete('supplier-orders/{supplierOrder}', [SupplierOrderController::class, 'destroy']);
        });
        Route::post('suppliers/merge', [SupplierController::class, 'merge'])
            ->middleware('can:suppliers.delete');
        Route::delete('suppliers/{supplier}', [SupplierController::class, 'destroy'])
            ->middleware('can:suppliers.delete');

        // Finance — money-in (A/R) and money-out (costs).
        Route::middleware('can:finance.view')->group(function () {
            Route::get('inflows/aging', [InflowController::class, 'aging']); // static before {inflow}
            Route::get('inflows/analytics', [InflowController::class, 'analytics']);
            Route::get('inflows', [InflowController::class, 'index']);
            Route::get('inflows/{inflow}/changes', [InflowController::class, 'changes']); // static before {inflow}
            Route::get('inflows/{inflow}', [InflowController::class, 'show']);
            Route::get('orders/{order}/payments', [OrderPaymentController::class, 'index']);
            // Static segments before the {cost} wildcard.
            Route::get('costs/categories', [CostController::class, 'categories']);
            Route::get('costs/group-counts', [CostController::class, 'groupCounts']);
            Route::get('costs/analytics', [CostController::class, 'analytics']);
            Route::get('costs', [CostController::class, 'index']);
            Route::get('costs/{cost}', [CostController::class, 'show']);
            Route::get('cash-flow', [CashFlowController::class, 'index']);
        });
        Route::middleware('can:finance.manage')->group(function () {
            Route::post('inflows', [InflowController::class, 'store']);
            Route::patch('inflows/{inflow}/status', [InflowController::class, 'updateStatus']);
            Route::patch('inflows/{inflow}', [InflowController::class, 'update']);
            Route::post('orders/{order}/payments', [OrderPaymentController::class, 'store']);
            Route::post('costs', [CostController::class, 'store']);
            Route::patch('costs/{cost}/status', [CostController::class, 'updateStatus']);
            Route::patch('costs/{cost}', [CostController::class, 'update']);
            Route::post('costs/{cost}/attachments', [CostController::class, 'addAttachment']);
            Route::delete('costs/{cost}/attachments/{attachment}', [CostController::class, 'deleteAttachment']);
        });
        Route::middleware('can:finance.delete')->group(function () {
            Route::delete('inflows/{inflow}', [InflowController::class, 'destroy']);
            Route::delete('costs/{cost}', [CostController::class, 'destroy']);
        });

        // AI data entry — upload → async extraction → review → commit. Module
        // gating (Module::AiDataEntry) is applied by the tenant group via the
        // `ai-imports` path prefix in ModuleRegistry.
        Route::middleware('can:ai.use')->group(function () {
            Route::get('ai-imports', [AiImportController::class, 'index']);
            Route::get('ai-imports/{aiImport}', [AiImportController::class, 'show']);
        });
        Route::middleware('can:ai.manage')->group(function () {
            Route::post('ai-imports', [AiImportController::class, 'store']);
            Route::patch('ai-imports/{aiImport}/lines/{line}', [AiImportController::class, 'updateLine']);
            Route::post('ai-imports/{aiImport}/commit', [AiImportController::class, 'commit']);
            Route::delete('ai-imports/{aiImport}', [AiImportController::class, 'destroy']);
        });

        // Customer-level consignment (rollup + FIFO sale/return across placements).
        Route::get('customers/{customer}/consignment', [CustomerConsignmentController::class, 'summary'])
            ->middleware('can:orders.view');
        Route::middleware('can:orders.manage')->group(function () {
            Route::post('customers/{customer}/consignment/place', [CustomerConsignmentController::class, 'place']);
            Route::post('customers/{customer}/consignment/sale', [CustomerConsignmentController::class, 'sale']);
            Route::post('customers/{customer}/consignment/return', [CustomerConsignmentController::class, 'recordReturn']);
        });

        // General presigned upload URL (any member; the attach step is gated).
        Route::post('uploads/presign', [UploadController::class, 'presign']);
        // Background-removal proxy (key stays server-side).
        Route::post('uploads/remove-background', [UploadController::class, 'removeBackground']);

        // In-app notification feed (any member).
        Route::get('notifications', [NotificationController::class, 'index']);
        Route::post('notifications/read', [NotificationController::class, 'read']);

        // Web push: register/unregister this device for the authenticated user.
        Route::post('push-subscriptions', [PushSubscriptionController::class, 'store']);
        Route::delete('push-subscriptions', [PushSubscriptionController::class, 'destroy']);

        // Team task planning (any member). Static segments precede the {workOrder} wildcard.
        Route::get('work-orders/stats', [WorkOrderController::class, 'stats']);
        Route::post('work-orders/reorder', [WorkOrderController::class, 'reorder']);
        Route::get('work-orders', [WorkOrderController::class, 'index']);
        Route::post('work-orders', [WorkOrderController::class, 'store']);
        Route::get('work-orders/{workOrder}', [WorkOrderController::class, 'show']);
        Route::patch('work-orders/{workOrder}/status', [WorkOrderController::class, 'updateStatus']);
        Route::patch('work-orders/{workOrder}', [WorkOrderController::class, 'update']);
        Route::delete('work-orders/{workOrder}', [WorkOrderController::class, 'destroy']);

        // Orders.
        Route::middleware('can:orders.view')->group(function () {
            // Static segment before the {order} wildcard; analytics needs financial visibility.
            Route::get('orders/analytics', [OrderController::class, 'analytics'])->middleware('can:financials.view');
            Route::get('orders', [OrderController::class, 'index']);
            Route::get('orders/{order}', [OrderController::class, 'show']);
            Route::get('orders/{order}/consignment', [ConsignmentController::class, 'summary']);
            // Comments (participation): any order viewer; edit/delete is author/admin.
            Route::post('orders/{order}/comments', [OrderCommentController::class, 'store']);
            Route::patch('order-comments/{orderNote}', [OrderCommentController::class, 'update']);
            Route::delete('order-comments/{orderNote}', [OrderCommentController::class, 'destroy']);
        });
        Route::middleware('can:orders.manage')->group(function () {
            Route::post('orders', [OrderController::class, 'store']);
            Route::patch('orders/{order}/status', [OrderController::class, 'updateStatus']);
            Route::post('orders/{order}/items', [OrderController::class, 'addItems']);
            Route::post('orders/{order}/consignment/sale', [ConsignmentController::class, 'sale']);
            Route::post('orders/{order}/consignment/return', [ConsignmentController::class, 'recordReturn']);
            Route::post('orders/{order}/consignment/close', [ConsignmentController::class, 'close']);
            Route::patch('orders/{order}/shipping', [OrderController::class, 'updateShipping']);
            Route::patch('orders/{order}/notes', [OrderController::class, 'updateNotes']);
            Route::patch('order-items/{orderItem}/cost', [OrderController::class, 'updateItemCost']);
            Route::patch('order-items/{orderItem}', [OrderController::class, 'updateItem']);
            Route::delete('order-items/{orderItem}', [OrderController::class, 'deleteItem']);
        });
        Route::patch('orders/{order}/backorder', [OrderController::class, 'updateBackorder'])
            ->middleware('can:orders.backorder');
        Route::delete('orders/{order}', [OrderController::class, 'destroy'])
            ->middleware('can:orders.delete');

        // Pricing tiers.
        Route::middleware('can:pricing.view')->group(function () {
            Route::get('pricing-tiers', [PricingTierController::class, 'index']);

            // Per-item price books (read).
            Route::get('inventory-items/{item}/tier-prices', [PriceController::class, 'itemTierPrices']);
            Route::get('inventory-items/{item}/customer-prices', [PriceController::class, 'itemCustomerPrices']);
        });
        Route::middleware('can:pricing.manage')->group(function () {
            Route::post('pricing-tiers', [PricingTierController::class, 'store']);
            Route::patch('pricing-tiers/{pricingTier}', [PricingTierController::class, 'update']);
            Route::delete('pricing-tiers/{pricingTier}', [PricingTierController::class, 'destroy']);

            // Per-item price books.
            Route::put('inventory-items/{item}/tier-price/{tier}', [PriceController::class, 'upsertTierPrice']);
            Route::delete('inventory-items/{item}/tier-price/{tier}', [PriceController::class, 'destroyTierPrice']);
            Route::put('inventory-items/{item}/customer-price/{customer}', [PriceController::class, 'upsertCustomerPrice']);
            Route::delete('inventory-items/{item}/customer-price/{customer}', [PriceController::class, 'destroyCustomerPrice']);
        });

        // Cellar — vessels (incl. cellar map) and wine lots. Module gated by the
        // `vessels` / `wine-lots` path prefixes (App\Authorization\ModuleRegistry).
        Route::middleware('can:cellar.view')->group(function () {
            Route::get('cellar/fermentation-monitor', [FermentationMonitorController::class, 'index']);
            Route::get('cellar/costs', [CellarController::class, 'costs']);
            Route::get('cellar/analytics', [CellarController::class, 'analytics']);
            Route::get('tasting-reports', [TastingReportController::class, 'index']);
            Route::get('vessels', [VesselController::class, 'index']);
            Route::get('vessels/{vessel}', [VesselController::class, 'show']);
            Route::get('wine-lots', [WineLotController::class, 'index']);
            Route::get('wine-lots/{wineLot}/analyses/trend', [WineLotController::class, 'analysisTrend']);
            Route::get('wine-lots/{wineLot}', [WineLotController::class, 'show']);
            Route::get('enological-products', [EnologicalProductController::class, 'index']);
            Route::get('fermentation-templates', [FermentationTemplateController::class, 'index']);
        });
        Route::middleware('can:cellar.manage')->group(function () {
            Route::post('vessels', [VesselController::class, 'store']);
            Route::post('vessels/bulk', [VesselController::class, 'bulkCreate']);
            Route::post('vessels/rename-room', [VesselController::class, 'renameRoom']);
            Route::patch('vessels/layout', [VesselController::class, 'layout']);
            Route::patch('vessels/{vessel}', [VesselController::class, 'update']);

            Route::post('wine-lots', [WineLotController::class, 'store']);
            Route::patch('wine-lots/{wineLot}', [WineLotController::class, 'update']);
            Route::post('wine-lots/{wineLot}/vessels', [WineLotController::class, 'assignVessel']);
            Route::delete('wine-lots/{wineLot}/vessels/{vesselLot}', [WineLotController::class, 'unassignVessel']);
            Route::post('wine-lots/{wineLot}/adjust-volume', [WineLotController::class, 'adjustVolume']);

            // Lot lifecycle (analyses, additions, processes, tastings, transfers, bottling).
            Route::post('wine-lots/analyses/bulk', [WineLotController::class, 'bulkAnalyses']);
            Route::post('wine-lots/additions/bulk', [WineLotController::class, 'bulkAdditions']);
            Route::post('wine-lots/blend', [WineLotController::class, 'blend']);
            Route::post('tasting-reports', [TastingReportController::class, 'store']);
            Route::post('wine-lots/{wineLot}/analyses', [WineLotController::class, 'addAnalysis']);
            Route::patch('wine-lots/{wineLot}/analyses/{analysis}', [WineLotController::class, 'updateAnalysis']);
            Route::delete('wine-lots/{wineLot}/analyses/{analysis}', [WineLotController::class, 'deleteAnalysis']);
            Route::post('wine-lots/{wineLot}/additions', [WineLotController::class, 'addAddition']);
            Route::delete('wine-lots/{wineLot}/additions/{addition}', [WineLotController::class, 'deleteAddition']);
            Route::post('wine-lots/{wineLot}/processes', [WineLotController::class, 'addProcess']);
            Route::delete('wine-lots/{wineLot}/processes/{process}', [WineLotController::class, 'deleteProcess']);
            Route::post('wine-lots/{wineLot}/tasting-notes', [WineLotController::class, 'addTasting']);
            Route::delete('wine-lots/{wineLot}/tasting-notes/{tastingNote}', [WineLotController::class, 'deleteTasting']);
            Route::post('wine-lots/{wineLot}/transfers', [WineLotController::class, 'addTransfer']);
            Route::delete('wine-lots/{wineLot}/transfers/{transfer}', [WineLotController::class, 'deleteTransfer']);
            Route::post('wine-lots/{wineLot}/bottlings', [WineLotController::class, 'addBottling']);
            Route::delete('wine-lots/{wineLot}/bottlings/{bottling}', [WineLotController::class, 'deleteBottling']);

            // Enological products.
            Route::post('enological-products', [EnologicalProductController::class, 'store']);
            Route::patch('enological-products/{enologicalProduct}', [EnologicalProductController::class, 'update']);
            Route::post('enological-products/{enologicalProduct}/adjust-stock', [EnologicalProductController::class, 'adjustStock']);

            // Fermentation protocols.
            Route::post('fermentation-templates', [FermentationTemplateController::class, 'store']);
            Route::patch('fermentation-templates/{fermentationTemplate}', [FermentationTemplateController::class, 'update']);
            Route::post('wine-lots/{wineLot}/protocol', [WineLotController::class, 'assignProtocol']);
            Route::post('wine-lots/{wineLot}/protocol/generate', [WineLotController::class, 'generateProtocol']);
        });
        Route::middleware('can:cellar.delete')->group(function () {
            Route::delete('vessels/{vessel}', [VesselController::class, 'destroy']);
            Route::delete('enological-products/{enologicalProduct}', [EnologicalProductController::class, 'destroy']);
            Route::delete('fermentation-templates/{fermentationTemplate}', [FermentationTemplateController::class, 'destroy']);
        });

        // Vineyards — parcels (+ agronomy), contracts, harvest plans + intake, bookings.
        Route::middleware('can:vineyards.view')->group(function () {
            Route::get('vineyard-parcels', [VineyardParcelController::class, 'index']);
            Route::get('vineyard-parcels/{vineyardParcel}', [VineyardParcelController::class, 'show']);
            Route::get('grape-contracts', [GrapeContractController::class, 'index']);
            Route::get('grape-contracts/performance/{supplier}', [GrapeContractController::class, 'performance']);
            Route::get('harvest-plans', [HarvestPlanController::class, 'index']);
            Route::get('harvest-plans/{harvestPlan}', [HarvestPlanController::class, 'show']);
            Route::get('intake-bookings', [IntakeBookingController::class, 'index']);
        });
        Route::middleware('can:vineyards.manage')->group(function () {
            Route::post('vineyard-parcels', [VineyardParcelController::class, 'store']);
            Route::patch('vineyard-parcels/{vineyardParcel}', [VineyardParcelController::class, 'update']);
            Route::post('vineyard-parcels/{vineyardParcel}/maturity-samples', [VineyardParcelController::class, 'addMaturitySample']);
            Route::post('vineyard-parcels/{vineyardParcel}/phenology-logs', [VineyardParcelController::class, 'addPhenologyLog']);
            Route::post('vineyard-parcels/{vineyardParcel}/crop-estimates', [VineyardParcelController::class, 'addCropEstimate']);
            Route::post('vineyard-parcels/{vineyardParcel}/applications', [VineyardParcelController::class, 'addApplication']);
            Route::delete('vineyard-parcels/{vineyardParcel}/maturity-samples/{sample}', [VineyardParcelController::class, 'deleteMaturitySample']);
            Route::delete('vineyard-parcels/{vineyardParcel}/phenology-logs/{log}', [VineyardParcelController::class, 'deletePhenologyLog']);
            Route::delete('vineyard-parcels/{vineyardParcel}/crop-estimates/{estimate}', [VineyardParcelController::class, 'deleteCropEstimate']);
            Route::delete('vineyard-parcels/{vineyardParcel}/applications/{application}', [VineyardParcelController::class, 'deleteApplication']);

            Route::post('grape-contracts', [GrapeContractController::class, 'store']);
            Route::patch('grape-contracts/{grapeContract}', [GrapeContractController::class, 'update']);

            Route::post('harvest-plans', [HarvestPlanController::class, 'store']);
            Route::patch('harvest-plans/{harvestPlan}', [HarvestPlanController::class, 'update']);
            Route::post('harvest-plans/{harvestPlan}/entries', [HarvestPlanController::class, 'addEntry']);
            Route::delete('harvest-plans/{harvestPlan}/entries/{harvestEntry}', [HarvestPlanController::class, 'removeEntry']);
            Route::post('harvest-plans/{harvestPlan}/entries/{harvestEntry}/intake', [HarvestPlanController::class, 'recordIntake']);

            Route::post('intake-bookings', [IntakeBookingController::class, 'store']);
            Route::patch('intake-bookings/{intakeBooking}', [IntakeBookingController::class, 'update']);
        });
        Route::middleware('can:vineyards.delete')->group(function () {
            Route::delete('vineyard-parcels/{vineyardParcel}', [VineyardParcelController::class, 'destroy']);
            Route::delete('grape-contracts/{grapeContract}', [GrapeContractController::class, 'destroy']);
            Route::delete('harvest-plans/{harvestPlan}', [HarvestPlanController::class, 'destroy']);
            Route::delete('intake-bookings/{intakeBooking}', [IntakeBookingController::class, 'destroy']);
        });

        // Production — the planner (plans, calculate, confirm/vintage management).
        Route::middleware('can:production.view')->group(function () {
            Route::get('production-plans', [ProductionPlanController::class, 'index']);
            Route::get('production-plans/{productionPlan}', [ProductionPlanController::class, 'show']);
            Route::get('production-plans/{productionPlan}/calculate', [ProductionPlanController::class, 'calculate']);
        });
        Route::middleware('can:production.manage')->group(function () {
            Route::post('production-plans', [ProductionPlanController::class, 'store']);
            Route::patch('production-plans/{productionPlan}', [ProductionPlanController::class, 'update']);
            Route::post('production-plans/{productionPlan}/confirm', [ProductionPlanController::class, 'confirm']);
        });
        Route::middleware('can:production.delete')->group(function () {
            Route::delete('production-plans/{productionPlan}', [ProductionPlanController::class, 'destroy']);
        });
    });
});
