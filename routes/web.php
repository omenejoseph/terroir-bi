<?php

declare(strict_types=1);

use App\Http\Controllers\Web\Auth\LoginController;
use App\Http\Controllers\Web\Auth\TenantSwitchController;
use App\Http\Controllers\Web\DashboardController;
use App\Http\Controllers\Web\InventoryController;
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
        Route::get('inventory/{item}', [InventoryController::class, 'show'])->name('inventory.show');
    });

    Route::middleware('can:inventory.manage')->group(function () {
        Route::post('inventory', [InventoryController::class, 'store'])->name('inventory.store');
        Route::patch('inventory/{item}', [InventoryController::class, 'update'])->name('inventory.update');
        Route::post('inventory/{item}/stock', [InventoryController::class, 'adjustStock'])
            ->name('inventory.stock.adjust');
        Route::patch('inventory-bulk', [InventoryController::class, 'bulkUpdate'])->name('inventory.bulk-update');
    });

    Route::delete('inventory/{item}', [InventoryController::class, 'destroy'])
        ->middleware('can:inventory.delete')
        ->name('inventory.destroy');
});
