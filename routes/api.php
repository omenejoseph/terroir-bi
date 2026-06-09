<?php

use App\Http\Controllers\Api\Auth\AuthController;
use App\Http\Controllers\Api\Auth\TenantSessionController;
use App\Http\Controllers\Api\ConsignmentController;
use App\Http\Controllers\Api\CustomerController;
use App\Http\Controllers\Api\CustomerProductOverrideController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\InventoryItemController;
use App\Http\Controllers\Api\InvitationController;
use App\Http\Controllers\Api\MemberController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\OrderCommentController;
use App\Http\Controllers\Api\OrderController;
use App\Http\Controllers\Api\PriceController;
use App\Http\Controllers\Api\PricingTierController;
use App\Http\Controllers\Api\PublicOrderController;
use App\Http\Controllers\Api\SettingsController;
use App\Http\Controllers\Api\StockController;
use App\Http\Controllers\Api\TranslationController;
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

        // Localization — read for any member; writes require admin.
        Route::get('translations', [TranslationController::class, 'index']);
        Route::middleware('can:translations.manage')->group(function () {
            Route::put('translations', [TranslationController::class, 'upsert']);
            Route::delete('translations', [TranslationController::class, 'destroy']);
        });

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
            Route::get('inventory-items', [InventoryItemController::class, 'index']);
            Route::get('inventory-items/{item}', [InventoryItemController::class, 'show']);
            Route::get('inventory-items/{item}/movements', [StockController::class, 'movements']);
            Route::get('inventory-items/{item}/recipe', [StockController::class, 'recipe']);
        });
        Route::middleware('can:inventory.manage')->group(function () {
            // Static segment before the {item} wildcard so it isn't treated as an id.
            Route::post('inventory-items/check', [StockController::class, 'check']);
            Route::post('inventory-items', [InventoryItemController::class, 'store']);
            Route::patch('inventory-items/{item}', [InventoryItemController::class, 'update']);
            Route::post('inventory-items/{item}/stock', [StockController::class, 'adjust']);
            Route::post('inventory-items/{item}/produce', [StockController::class, 'produce']);
            Route::put('inventory-items/{item}/recipe', [StockController::class, 'setRecipe']);
            Route::patch('stock-movements/{stockMovement}/reconciliation', [StockController::class, 'setReconciliation']);
        });
        Route::delete('inventory-items/{item}', [InventoryItemController::class, 'destroy'])
            ->middleware('can:inventory.delete');

        // Customers.
        Route::middleware('can:customers.view')->group(function () {
            // Static segments before the {customer} wildcard so they aren't treated as ids.
            Route::get('customers/lookup-vat', [CustomerController::class, 'lookupVat']);
            Route::get('customers', [CustomerController::class, 'index']);
            Route::get('customers/{customer}', [CustomerController::class, 'show']);
            Route::get('customers/{customer}/insights', [CustomerController::class, 'insights'])->middleware('can:financials.view');
            Route::get('customers/{customer}/resolved-prices', [PriceController::class, 'resolvedPrices']);
            Route::get('customers/{customer}/product-overrides', [CustomerProductOverrideController::class, 'index']);
        });
        Route::middleware('can:customers.manage')->group(function () {
            Route::post('customers', [CustomerController::class, 'store']);
            Route::post('customers/quick', [CustomerController::class, 'quickStore']);
            Route::patch('customers/{customer}', [CustomerController::class, 'update']);
            Route::put('customers/{customer}/product-overrides/{item}', [CustomerProductOverrideController::class, 'upsert']);
            Route::delete('customers/{customer}/product-overrides/{item}', [CustomerProductOverrideController::class, 'destroy']);
        });
        Route::delete('customers/{customer}', [CustomerController::class, 'destroy'])
            ->middleware('can:customers.delete');
        Route::middleware('can:customers.tokens')->group(function () {
            Route::post('customers/{customer}/order-token', [CustomerController::class, 'generateToken']);
            Route::delete('customers/{customer}/order-token', [CustomerController::class, 'revokeToken']);
        });

        // In-app notification feed (any member).
        Route::get('notifications', [NotificationController::class, 'index']);
        Route::post('notifications/read', [NotificationController::class, 'read']);

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
    });
});
