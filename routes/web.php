<?php

declare(strict_types=1);

use App\Http\Controllers\Web\Auth\LoginController;
use App\Http\Controllers\Web\Auth\TenantSwitchController;
use App\Http\Controllers\Web\DashboardController;
use App\Http\Controllers\Web\InventoryController;
use App\Http\Controllers\Web\OrderController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web routes — the Inertia (Vue 3) application
|--------------------------------------------------------------------------
|
| These are session-authenticated and served from Laravel itself, unlike
| routes/api.php which stays token-authenticated for the public order and
| supplier portals and any non-browser client.
|
| Both call the same Actions/Queries/Services; only the envelope differs. As
| each module is ported from the Next.js app in frontend/, add its routes here
| and move it out of PENDING_MODULES in resources/js/Lib/navigation.ts.
|
*/

Route::redirect('/', '/dashboard');

// Guests only — an authenticated visit to /login bounces to the dashboard.
Route::middleware('guest')->group(function () {
    Route::get('login', [LoginController::class, 'create'])->name('login');
    Route::post('login', [LoginController::class, 'store']);
});

// Authenticated, but no active tenant required: a user whose current tenant
// context is unusable must still be able to sign out or switch away from it.
Route::middleware('auth')->group(function () {
    Route::post('logout', [LoginController::class, 'destroy'])->name('logout');
    Route::post('tenant/switch', [TenantSwitchController::class, 'store'])->name('tenant.switch');
});

// Authenticated + active tenant + verified membership + plan/subscription checks.
Route::middleware('tenant.web')->group(function () {
    Route::get('dashboard', DashboardController::class)->name('dashboard');

    Route::middleware('can:inventory.view')->group(function () {
        Route::get('inventory', [InventoryController::class, 'index'])->name('inventory.index');
        Route::get('inventory-analytics', [InventoryController::class, 'analytics'])->name('inventory.analytics');
        Route::get('inventory-check', [InventoryController::class, 'check'])->name('inventory.check');
        Route::get('inventory-spend', [InventoryController::class, 'spend'])
            ->middleware('can:financials.view')
            ->name('inventory.spend');
        Route::get('inventory/{item}', [InventoryController::class, 'show'])->name('inventory.show');
    });

    Route::middleware('can:inventory.manage')->group(function () {
        Route::post('inventory', [InventoryController::class, 'store'])->name('inventory.store');
        Route::patch('inventory/{item}', [InventoryController::class, 'update'])->name('inventory.update');
        Route::post('inventory/{item}/stock', [InventoryController::class, 'adjustStock'])
            ->name('inventory.stock.adjust');
        Route::patch('inventory-bulk', [InventoryController::class, 'bulkUpdate'])->name('inventory.bulk-update');
        Route::post('inventory-check', [InventoryController::class, 'applyCheck'])->name('inventory.check.apply');
    });

    Route::delete('inventory/{item}', [InventoryController::class, 'destroy'])
        ->middleware('can:inventory.delete')
        ->name('inventory.destroy');

    /*
      Orders. The gates mirror routes/api.php exactly: viewing and commenting
      need orders.view, every write needs orders.manage, deletion is admin-only
      via orders.delete. The unauthenticated public token order page stays on
      the API — it resolves its tenant from the token, not from the session.
    */
    Route::middleware('can:orders.view')->group(function () {
        Route::get('orders', [OrderController::class, 'index'])->name('orders.index');
        // Participation, not management: any order viewer may comment.
        Route::post('orders/{order}/comments', [OrderController::class, 'storeComment'])
            ->name('orders.comments.store');
    });

    Route::middleware('can:orders.manage')->group(function () {
        Route::post('orders', [OrderController::class, 'store'])->name('orders.store');
        Route::patch('orders/{order}/status', [OrderController::class, 'updateStatus'])
            ->name('orders.status.update');
        Route::patch('orders/{order}/notes', [OrderController::class, 'updateNotes'])
            ->name('orders.notes.update');
    });

    Route::delete('orders/{order}', [OrderController::class, 'destroy'])
        ->middleware('can:orders.delete')
        ->name('orders.destroy');
});
