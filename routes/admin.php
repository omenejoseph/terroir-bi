<?php

declare(strict_types=1);

use App\Http\Controllers\Web\Admin\DashboardController;
use App\Http\Controllers\Web\Admin\PlanController;
use App\Http\Controllers\Web\Admin\PlatformAdminController;
use App\Http\Controllers\Web\Admin\TenantController;
use App\Http\Controllers\Web\Admin\TenantMemberController;
use App\Http\Controllers\Web\Admin\TranslationOverrideController;
use App\Http\Controllers\Web\Admin\UserController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Platform-admin routes — the Inertia "/admin" back office
|--------------------------------------------------------------------------
|
| Replaces the Filament panel currently mounted at /admin (app/Filament/**,
| App\Providers\Filament\AdminPanelProvider) — retired once every screen below
| is ported and verified, per the retirement plan. Gated by the platform.admin
| middleware group (bootstrap/app.php): auth + is_platform_admin only. This is
| intentionally NOT routes/web.php's tenant.web chain — a platform admin need
| not be a member of any tenant, and ResolveTenant would 400/403 one who isn't.
|
| TEMP: mounted at /admin-new while Filament still owns /admin, so both can be
| reviewed side by side. Renamed to /admin in the same step that unregisters
| AdminPanelProvider (see the plan's Tier 4 cutover) — search the codebase for
| "admin-new" when that day comes; resources/js/lib/adminNavigation.ts carries
| the matching frontend constant.
*/

Route::middleware('platform.admin')->prefix('admin-new')->name('admin.')->group(function () {
    Route::get('/', DashboardController::class)->name('dashboard');

    Route::get('translation-overrides', [TranslationOverrideController::class, 'index'])
        ->name('translation-overrides.index');
    Route::post('translation-overrides', [TranslationOverrideController::class, 'store'])
        ->name('translation-overrides.store');
    Route::patch('translation-overrides/{translationOverride}', [TranslationOverrideController::class, 'update'])
        ->name('translation-overrides.update');
    Route::delete('translation-overrides/{translationOverride}', [TranslationOverrideController::class, 'destroy'])
        ->name('translation-overrides.destroy');

    Route::get('platform-admins', [PlatformAdminController::class, 'index'])->name('platform-admins.index');
    Route::post('platform-admins', [PlatformAdminController::class, 'store'])->name('platform-admins.store');
    Route::post('platform-admins/promote', [PlatformAdminController::class, 'promote'])
        ->name('platform-admins.promote');
    Route::delete('platform-admins/{user}', [PlatformAdminController::class, 'revoke'])
        ->name('platform-admins.revoke');

    Route::get('users', [UserController::class, 'index'])->name('users.index');
    Route::get('users/{user}', [UserController::class, 'show'])->name('users.show');

    Route::get('plans', [PlanController::class, 'index'])->name('plans.index');
    Route::post('plans', [PlanController::class, 'store'])->name('plans.store');
    Route::get('plans/{plan}', [PlanController::class, 'show'])->name('plans.show');
    Route::patch('plans/{plan}', [PlanController::class, 'update'])->name('plans.update');
    Route::delete('plans/{plan}', [PlanController::class, 'destroy'])->name('plans.destroy');
    Route::post('plans/{plan}/create-stripe-price', [PlanController::class, 'createStripePrice'])
        ->name('plans.create-stripe-price');

    Route::get('tenants', [TenantController::class, 'index'])->name('tenants.index');
    Route::post('tenants', [TenantController::class, 'store'])->name('tenants.store');
    Route::get('tenants/{tenant}', [TenantController::class, 'show'])->name('tenants.show');
    Route::patch('tenants/{tenant}/status', [TenantController::class, 'updateStatus'])->name('tenants.update-status');
    Route::patch('tenants/{tenant}/plan', [TenantController::class, 'assignPlan'])->name('tenants.assign-plan');
    Route::post('tenants/{tenant}/onboarding-link', [TenantController::class, 'generateOnboardingLink'])
        ->name('tenants.generate-onboarding-link');
    Route::post('tenants/{tenant}/email-billing-link', [TenantController::class, 'emailBillingLink'])
        ->name('tenants.email-billing-link');

    Route::post('tenants/{tenant}/members', [TenantMemberController::class, 'store'])->name('tenant-members.store');
    Route::patch('tenants/{tenant}/members/{member}', [TenantMemberController::class, 'update'])
        ->name('tenant-members.update');
    Route::delete('tenants/{tenant}/members/{member}', [TenantMemberController::class, 'destroy'])
        ->name('tenant-members.destroy');

    // Tier 3+: bdd-scenarios, bdd-access, ai-settings, ai-spend,
    // stripe-settings, broadcast — added as each tier lands (see the plan).
});
